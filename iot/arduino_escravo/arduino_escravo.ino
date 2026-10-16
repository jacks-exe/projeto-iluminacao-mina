// ============================================================================
// ARDUINO UNO (RECEPTOR DA MAQUETE): MX-RM-5V NO PINO 12 + 5 POSTES PWM
// ============================================================================

const int PINO_RX_RF = 12; // Pino de dados do receptor no Uno
const int NUM_TORRES = 5;
// Portas PWM disponíveis no Arduino Uno para os 5 postes
const int PINOS_POSTES[NUM_TORRES] = {3, 5, 6, 9, 10};

unsigned long ultimoAviso = 0;
unsigned long ultimoPacoteValido = 0;
unsigned long contadorTransicoes = 0;
int estadoAntPino = LOW;

void setup() {
  Serial.begin(115200);
  pinMode(PINO_RX_RF, INPUT);
  pinMode(LED_BUILTIN, OUTPUT);

  // Inicializa os 5 postes e faz um teste rápido de luz
  for (int i = 0; i < NUM_TORRES; i++) {
    pinMode(PINOS_POSTES[i], OUTPUT);
    analogWrite(PINOS_POSTES[i], 200);
    delay(120);
    analogWrite(PINOS_POSTES[i], 0);
  }

  Serial.println("=== ARDUINO UNO (MAQUETE) PRONTO - ESCUTANDO PINO 12 ===");
}

unsigned long medirPulsoHigh(unsigned long timeoutMicros) {
  unsigned long t0 = micros();
  while (digitalRead(PINO_RX_RF) == HIGH) {
    if (micros() - t0 > timeoutMicros) return 0;
  }
  while (digitalRead(PINO_RX_RF) == LOW) {
    if (micros() - t0 > timeoutMicros) return 0;
  }
  unsigned long inicioHigh = micros();
  while (digitalRead(PINO_RX_RF) == HIGH) {
    if (micros() - inicioHigh > timeoutMicros) return 0;
  }
  return micros() - inicioHigh;
}

void loop() {
  int leituraAtual = digitalRead(PINO_RX_RF);
  if (leituraAtual != estadoAntPino) {
    contadorTransicoes++;
    estadoAntPino = leituraAtual;
  }

  // Procura pelo pulso de START (3500us HIGH) enviado pelo Mega
  unsigned long duracaoStart = medirPulsoHigh(15000);

  if (duracaoStart >= 2600 && duracaoStart <= 4500) {
    byte buffer[NUM_TORRES + 1];
    bool ok = true;

    for (int i = 0; i < NUM_TORRES + 1; i++) {
      byte b = 0;
      for (int bit = 7; bit >= 0; bit--) {
        unsigned long p = medirPulsoHigh(4000);
        if (p >= 900 && p <= 1700) {
          bitWrite(b, bit, 1);
        } else if (p >= 300 && p < 900) {
          bitWrite(b, bit, 0);
        } else {
          ok = false;
          break;
        }
      }
      if (!ok) break;
      buffer[i] = b;
    }

    if (ok) {
      byte soma = 0xAA;
      for (int i = 0; i < NUM_TORRES; i++) soma += buffer[i];

      if (soma == buffer[NUM_TORRES]) {
        ultimoPacoteValido = millis();
        digitalWrite(LED_BUILTIN, !digitalRead(LED_BUILTIN));

        Serial.print("[RF 433MHz OK] Uno atualizou postes: ");
        for (int i = 0; i < NUM_TORRES; i++) {
          analogWrite(PINOS_POSTES[i], buffer[i]);
          Serial.print(buffer[i]);
          if (i < NUM_TORRES - 1) Serial.print(", ");
        }
        Serial.println();
      }
    }
  }

  if (millis() - ultimoPacoteValido > 3000 && millis() - ultimoAviso > 3000) {
    ultimoAviso = millis();
    Serial.print("[DIAGNOSTICO UNO] Aguardando sinal... Transicoes no Pino 12: ");
    Serial.println(contadorTransicoes);
    contadorTransicoes = 0;
  }
}