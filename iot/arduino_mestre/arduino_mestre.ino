// ============================================================================
// ARDUINO MEGA 1 (MESTRE): TRANSMISSOR FS1000A NO PINO 18
// ============================================================================
// Olhando o Transmissor de frente (Foto 1):
// Pino Esquerda (GND)  -> GND do Mega 1
// Pino Meio     (VCC)  -> 5V do Mega 1
// Pino Direita  (ATAD) -> Pino 18 do Mega 1

const int PINO_TX_RF = 18;
const int PINO_LDR = A0;
const int PINO_BAT = A1;
const int BTN_CERRACAO = 2;
const int BTN_MANUTENCAO = 3;

const int NUM_TORRES = 5;
byte pwmTorres[NUM_TORRES] = {170, 170, 170, 170, 170};

bool modoCerracaoAtivo = false;
bool torre1EmManutencao = false;
bool modoManualWeb = false;

unsigned long ultimoEnvioRF = 0;
unsigned long ultimoLogUSB = 0;
bool estadoAntCerracao = HIGH;
bool estadoAntManut = HIGH;

void setup() {
  Serial.begin(115200);
  pinMode(PINO_TX_RF, OUTPUT);
  digitalWrite(PINO_TX_RF, LOW);
  pinMode(LED_BUILTIN, OUTPUT); // Pisca quando transmite

  pinMode(BTN_CERRACAO, INPUT_PULLUP);
  pinMode(BTN_MANUTENCAO, INPUT_PULLUP);

  Serial.println("=== MEGA 1 (MESTRE) PRONTO - TRANSMITINDO NO PINO 18 ===");
}

void loop() {
  lerBotoesFisicos();
  lerComandosUSB();
  processarRegrasDeNegocio();

  // Envia o pacote via RF a cada 150ms
  if (millis() - ultimoEnvioRF >= 150) {
    ultimoEnvioRF = millis();
    digitalWrite(LED_BUILTIN, HIGH);
    enviarPacoteRF();
    digitalWrite(LED_BUILTIN, LOW);
  }

  // Log no Monitor Serial a cada 500ms
  if (millis() - ultimoLogUSB >= 500) {
    ultimoLogUSB = millis();
    enviarTelemetriaUSB();
  }
}

void enviarPulso(int tempoHigh, int tempoLow) {
  digitalWrite(PINO_TX_RF, HIGH);
  delayMicroseconds(tempoHigh);
  digitalWrite(PINO_TX_RF, LOW);
  delayMicroseconds(tempoLow);
}

void enviarByteRF(byte b) {
  for (int i = 7; i >= 0; i--) {
    if (bitRead(b, i) == 1) {
      enviarPulso(1200, 600); // Bit 1 = 1200us HIGH
    } else {
      enviarPulso(600, 1200); // Bit 0 = 600us HIGH
    }
  }
}

void enviarPacoteRF() {
  // 1. Preâmbulo de 20 pulsos curtos para calibrar o receptor MX-RM-5V
  for (int i = 0; i < 20; i++) {
    enviarPulso(500, 500);
  }

  // 2. Pulso de START longo (3500us HIGH + 1000us LOW)
  enviarPulso(3500, 1000);

  // 3. Envia os 5 bytes das torres + 1 byte de Checksum
  byte checksum = 0xAA; // Semente inicial para evitar soma zero quando tudo apagado
  for (int i = 0; i < NUM_TORRES; i++) {
    enviarByteRF(pwmTorres[i]);
    checksum += pwmTorres[i];
  }
  enviarByteRF(checksum);

  digitalWrite(PINO_TX_RF, LOW);
}

void lerBotoesFisicos() {
  bool leituraCerracao = digitalRead(BTN_CERRACAO);
  if (leituraCerracao == LOW && estadoAntCerracao == HIGH) {
    modoCerracaoAtivo = !modoCerracaoAtivo;
    modoManualWeb = false;
    delay(200);
  }
  estadoAntCerracao = leituraCerracao;

  bool leituraManut = digitalRead(BTN_MANUTENCAO);
  if (leituraManut == LOW && estadoAntManut == HIGH) {
    torre1EmManutencao = !torre1EmManutencao;
    delay(200);
  }
  estadoAntManut = leituraManut;
}

void lerComandosUSB() {
  if (Serial.available() > 0) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    if (cmd.equalsIgnoreCase("AUTO")) {
      modoManualWeb = false;
      modoCerracaoAtivo = false;
    } else if (cmd.startsWith("SET:")) {
      modoManualWeb = true;
      String valores = cmd.substring(4);
      int idx = 0, inicio = 0;
      for (int i = 0; i <= valores.length() && idx < NUM_TORRES; i++) {
        if (i == valores.length() || valores.charAt(i) == ',') {
          pwmTorres[idx] = constrain(valores.substring(inicio, i).toInt(), 0, 255);
          inicio = i + 1;
          idx++;
        }
      }
    }
  }
}

void processarRegrasDeNegocio() {
  int valorLDR = analogRead(PINO_LDR);
  int nivelBateria = map(analogRead(PINO_BAT), 0, 1023, 0, 100);

  if (!modoManualWeb) {
    byte brilhoAlvo = 0;
    if (modoCerracaoAtivo) {
      brilhoAlvo = 255;
    } else if (valorLDR < 450) {
      brilhoAlvo = (nivelBateria < 25) ? 70 : 170;
    } else {
      brilhoAlvo = 0;
    }
    for (int i = 0; i < NUM_TORRES; i++) pwmTorres[i] = brilhoAlvo;
  }

  if (nivelBateria < 5) {
    for (int i = 0; i < NUM_TORRES; i++) pwmTorres[i] = 0;
  }
  if (torre1EmManutencao) pwmTorres[0] = 0;
}

void enviarTelemetriaUSB() {
  int valorLDR = analogRead(PINO_LDR);
  int nivelBateria = map(analogRead(PINO_BAT), 0, 1023, 0, 100);
  Serial.print("{\"ldr\":"); Serial.print(valorLDR);
  Serial.print(",\"bateria\":"); Serial.print(nivelBateria);
  Serial.print(",\"cerracao\":"); Serial.print(modoCerracaoAtivo ? "true" : "false");
  Serial.print(",\"torres\":[");
  for (int i = 0; i < NUM_TORRES; i++) {
    Serial.print(pwmTorres[i]);
    if (i < NUM_TORRES - 1) Serial.print(",");
  }
  Serial.println("]}");
}