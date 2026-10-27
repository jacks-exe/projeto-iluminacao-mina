// ============================================================================
// GEONEX SCADA - ARDUINO MEGA 2560 (PLACA ÚNICA / MESTRE - 115200 BPS)
// ============================================================================
// Controle direto dos 5 postes da maquete:
//   - Pinos PWM Principais: 2, 3, 4, 5 e 6
//   - Pinos Espelho Opcionais: 8, 9, 10, 11 e 12
//   - Acionamento Isolado: Altera SOMENTE a torre solicitada (Zero flicker nas demais)
//   - Comunicação Serial 100% Não-Bloqueante
// ============================================================================

#include <Arduino.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

const uint8_t NUM_POSTES = 5;

// Portas PWM no Arduino Mega para os 5 postes
const uint8_t PINOS_POSTES[NUM_POSTES]       = {2, 3, 4, 5, 6};
const uint8_t PINOS_ESPELHO_MEGA[NUM_POSTES] = {8, 9, 10, 11, 12};

uint8_t pwmAtual[NUM_POSTES]        = {170, 170, 170, 170, 170};
uint8_t pwmAlvo[NUM_POSTES]         = {170, 170, 170, 170, 170};
bool posteConectado[NUM_POSTES]     = {true, true, true, true, true};
bool posteEmManutencao[NUM_POSTES]  = {false, false, false, false, false};

float nivelBateriaVirtual = 92.0f;
uint16_t valorLdrVirtual  = 310;
bool modoCerracaoAtivo    = false;
bool modoManualWeb        = true;

const unsigned long INTERVALO_CASCATA_MS    = 150;
const unsigned long INTERVALO_FADE_MS       = 6;
const uint8_t PASSO_FADE                    = 15;
const unsigned long INTERVALO_TELEMETRIA_MS = 3000; // Telemetria calma a cada 3s

unsigned long instanteLiberacaoPoste[NUM_POSTES] = {0, 0, 0, 0, 0};
unsigned long ultimoPassoFadeMs     = 0;
unsigned long ultimoLogTelemetriaMs = 0;

bool cascataEmAndamento = false;

// Buffer serial não-bloqueante
char serialBuffer[64];
uint8_t serialIndex = 0;

void aplicarPwmFisico(uint8_t indice, uint8_t duty);
void aplicarAlvoImediato(uint8_t indice, uint8_t brilho);
void iniciarAcionamentoCascata(const uint8_t novosAlvos[NUM_POSTES], unsigned long intervaloMs);
void atualizarRampaProgressiva();
void lerComandosSerialNaoBloqueante();
void interpretarComando(char *cmd);
void emitirTelemetriaJson();

void setup() {
  Serial.begin(115200);

  pinMode(LED_BUILTIN, OUTPUT);
  digitalWrite(LED_BUILTIN, LOW);

  // Inicializa os pinos PWM das 5 torres
  for (uint8_t i = 0; i < NUM_POSTES; i++) {
    pinMode(PINOS_POSTES[i], OUTPUT);
    pinMode(PINOS_ESPELHO_MEGA[i], OUTPUT);
    pwmAtual[i] = 170;
    pwmAlvo[i]  = 170;
    aplicarPwmFisico(i, 170);
  }

  Serial.println(F("{\"evento\":\"BOOT\",\"dispositivo\":\"ARDUINO_MEGA_MESTRE\",\"baud\":115200,\"pinos\":[2,3,4,5,6],\"status\":\"ONLINE\"}"));
}

void loop() {
  unsigned long agoraMs = millis();

  // 1. Processamento serial contínuo sem bloqueios
  lerComandosSerialNaoBloqueante();

  // 2. Transições em cascata (quando acionadas)
  if (cascataEmAndamento) {
    atualizarRampaProgressiva();
  }

  // 3. Telemetria periódica
  if (agoraMs - ultimoLogTelemetriaMs >= INTERVALO_TELEMETRIA_MS) {
    ultimoLogTelemetriaMs = agoraMs;
    emitirTelemetriaJson();
  }
}

// Aplica duty cycle nos pinos de uma torre específica
void aplicarPwmFisico(uint8_t indice, uint8_t duty) {
  if (indice >= NUM_POSTES) return;

  uint8_t pinoPrincipal = PINOS_POSTES[indice];
  uint8_t pinoEspelho   = PINOS_ESPELHO_MEGA[indice];

  if (duty == 0) {
    digitalWrite(pinoPrincipal, LOW);
    digitalWrite(pinoEspelho, LOW);
  } else if (duty == 255) {
    digitalWrite(pinoPrincipal, HIGH);
    digitalWrite(pinoEspelho, HIGH);
  } else {
    analogWrite(pinoPrincipal, duty);
    analogWrite(pinoEspelho, duty);
  }
}

// Aplica novo valor ISOLADO em apenas UMA torre sem tocar nos pinos das outras (zero flicker)
void aplicarAlvoImediato(uint8_t indice, uint8_t brilho) {
  if (indice >= NUM_POSTES) return;

  uint8_t efetivo = (posteEmManutencao[indice] || !posteConectado[indice]) ? 0 : brilho;

  // Se o valor já é o mesmo, não reescreve os registros de hardware
  if (pwmAlvo[indice] == efetivo && pwmAtual[indice] == efetivo) {
    return;
  }

  pwmAlvo[indice]  = efetivo;
  pwmAtual[indice] = efetivo;
  instanteLiberacaoPoste[indice] = millis();

  // Altera estritamente o pino desta torre, deixando todas as outras intactas
  aplicarPwmFisico(indice, efetivo);
}

void iniciarAcionamentoCascata(const uint8_t novosAlvos[NUM_POSTES], unsigned long intervaloMs) {
  unsigned long agoraMs = millis();
  cascataEmAndamento = true;

  for (uint8_t i = 0; i < NUM_POSTES; i++) {
    uint8_t efetivo = (posteEmManutencao[i] || !posteConectado[i]) ? 0 : novosAlvos[i];
    pwmAlvo[i] = efetivo;
    if (efetivo == 0 && pwmAtual[i] == 0) {
      instanteLiberacaoPoste[i] = agoraMs;
      aplicarPwmFisico(i, 0);
    } else {
      instanteLiberacaoPoste[i] = agoraMs + (unsigned long)i * intervaloMs;
    }
  }
}

void atualizarRampaProgressiva() {
  unsigned long agoraMs = millis();
  if (agoraMs - ultimoPassoFadeMs < INTERVALO_FADE_MS) return;
  ultimoPassoFadeMs = agoraMs;

  bool algumEmTransicao = false;

  for (uint8_t i = 0; i < NUM_POSTES; i++) {
    if ((long)(agoraMs - instanteLiberacaoPoste[i]) < 0) {
      algumEmTransicao = true;
      continue;
    }

    uint8_t alvoEfetivo = (posteEmManutencao[i] || !posteConectado[i]) ? 0 : pwmAlvo[i];

    if (alvoEfetivo == 0) {
      if (pwmAtual[i] != 0) {
        pwmAtual[i] = 0;
        aplicarPwmFisico(i, 0);
      }
      continue;
    }

    if (pwmAtual[i] < alvoEfetivo) {
      uint16_t proximo = (uint16_t)pwmAtual[i] + PASSO_FADE;
      pwmAtual[i] = (proximo > alvoEfetivo) ? alvoEfetivo : (uint8_t)proximo;
      aplicarPwmFisico(i, pwmAtual[i]);
      algumEmTransicao = true;
    } else if (pwmAtual[i] > alvoEfetivo) {
      int16_t proximo = (int16_t)pwmAtual[i] - PASSO_FADE;
      pwmAtual[i] = (proximo < (int16_t)alvoEfetivo) ? alvoEfetivo : (uint8_t)proximo;
      aplicarPwmFisico(i, pwmAtual[i]);
      algumEmTransicao = true;
    }
  }

  cascataEmAndamento = algumEmTransicao;
}

// Leitura serial não-bloqueante
void lerComandosSerialNaoBloqueante() {
  while (Serial.available() > 0) {
    char c = (char)Serial.read();
    if (c == '\n' || c == '\r') {
      if (serialIndex > 0) {
        serialBuffer[serialIndex] = '\0';
        interpretarComando(serialBuffer);
        serialIndex = 0;
      }
    } else if (serialIndex < sizeof(serialBuffer) - 1) {
      serialBuffer[serialIndex++] = c;
    }
  }
}

void interpretarComando(char *cmd) {
  if (!cmd || cmd[0] == '\0' || cmd[0] == '{') return;

  for (uint8_t i = 0; cmd[i]; i++) {
    if (cmd[i] >= 'a' && cmd[i] <= 'z') cmd[i] -= 32;
  }

  modoManualWeb = true;

  // 1. Comando POSTE:id,pwm (Acionamento ou Desligamento Isolado)
  if (strncmp(cmd, "POSTE:", 6) == 0) {
    int idPoste = 0;
    int valPwm  = 0;
    if (sscanf(cmd + 6, "%d,%d", &idPoste, &valPwm) == 2) {
      if (idPoste >= 1 && idPoste <= (int)NUM_POSTES) {
        uint8_t idx  = (uint8_t)(idPoste - 1);
        uint8_t duty = (uint8_t)constrain(valPwm, 0, 255);
        modoCerracaoAtivo = false; // Sai do modo cerração ao alterar torre individual
        cascataEmAndamento = false;
        aplicarAlvoImediato(idx, duty); // Altera SOMENTE este poste, mantendo todos os outros intactos
      }
    }
    emitirTelemetriaJson();
    return;
  }

  // 2. Comando SET:val1,val2,val3,val4,val5 OU SET:id,pwm
  if (strncmp(cmd, "SET:", 4) == 0) {
    cascataEmAndamento = false;
    char *params = cmd + 4;
    char *contexto = NULL;
    char *token = strtok_r(params, ",", &contexto);
    uint8_t vals[NUM_POSTES];
    uint8_t count = 0;

    while (token != NULL && count < NUM_POSTES) {
      vals[count++] = (uint8_t)constrain(atoi(token), 0, 255);
      token = strtok_r(NULL, ",", &contexto);
    }

    if (count == 2 && vals[0] >= 1 && vals[0] <= NUM_POSTES) {
      modoCerracaoAtivo = false;
      aplicarAlvoImediato(vals[0] - 1, vals[1]);
    } else if (count == NUM_POSTES) {
      bool todos255 = true;
      for (uint8_t i = 0; i < NUM_POSTES; i++) {
        if (vals[i] != 255) { todos255 = false; break; }
      }
      modoCerracaoAtivo = todos255;
      for (uint8_t i = 0; i < NUM_POSTES; i++) {
        // SÓ aplica se o valor realmente mudou para não reiniciar timers PWM
        if (pwmAtual[i] != vals[i] || pwmAlvo[i] != vals[i]) {
          pwmAlvo[i]  = vals[i];
          pwmAtual[i] = vals[i];
          aplicarPwmFisico(i, vals[i]);
        }
      }
    }
    emitirTelemetriaJson();
    return;
  }

  // 3. Conectividade individual (CONN:id,estado)
  if (strncmp(cmd, "CONN:", 5) == 0) {
    int idPoste = 0;
    int estado  = 1;
    if (sscanf(cmd + 5, "%d,%d", &idPoste, &estado) == 2) {
      if (idPoste >= 1 && idPoste <= (int)NUM_POSTES) {
        uint8_t idx = (uint8_t)(idPoste - 1);
        posteConectado[idx] = (estado != 0);
        if (!posteConectado[idx]) {
          aplicarAlvoImediato(idx, 0);
        }
      }
    }
    emitirTelemetriaJson();
    return;
  }

  // 4. Manutenção individual (MANUT:id,estado)
  if (strncmp(cmd, "MANUT:", 6) == 0) {
    int idPoste = 0;
    int ativo   = 0;
    if (sscanf(cmd + 6, "%d,%d", &idPoste, &ativo) == 2) {
      if (idPoste >= 1 && idPoste <= (int)NUM_POSTES) {
        uint8_t idx = (uint8_t)(idPoste - 1);
        posteEmManutencao[idx] = (ativo != 0);
        if (posteEmManutencao[idx]) {
          aplicarAlvoImediato(idx, 0);
        }
      }
    }
    emitirTelemetriaJson();
    return;
  }

  // 5. Telemetria virtual (TELEM:bat,ldr)
  if (strncmp(cmd, "TELEM:", 6) == 0) {
    int bat = 92;
    int ldr = 310;
    if (sscanf(cmd + 6, "%d,%d", &bat, &ldr) == 2) {
      nivelBateriaVirtual = (float)constrain(bat, 0, 100);
      valorLdrVirtual     = (uint16_t)constrain(ldr, 0, 1023);

      modoCerracaoAtivo = false;
      cascataEmAndamento = false;

      // Regra SCADA Automática:
      // Dia (LDR >= 450) ou Bateria crítica (< 5%) -> 0 (OFF)
      // Noite + Bateria baixa (< 25%)              -> 70 (ECO)
      // Noite + Bateria normal                     -> 170 (NORMAL)
      uint8_t alvoAuto = 170;
      if (nivelBateriaVirtual < 5.0f || valorLdrVirtual >= 450) {
        alvoAuto = 0;
      } else if (nivelBateriaVirtual < 25.0f) {
        alvoAuto = 70;
      }

      for (uint8_t i = 0; i < NUM_POSTES; i++) {
        if (!posteEmManutencao[i] && posteConectado[i]) {
          if (pwmAtual[i] != alvoAuto || pwmAlvo[i] != alvoAuto) {
            pwmAlvo[i]  = alvoAuto;
            pwmAtual[i] = alvoAuto;
            aplicarPwmFisico(i, alvoAuto);
          }
        }
      }
    }
    emitirTelemetriaJson();
    return;
  }

  // 6. Efeito Cascata Sequencial
  if (strncmp(cmd, "CASCATA:", 8) == 0) {
    int pwmAlvoCascata = atoi(cmd + 8);
    pwmAlvoCascata = constrain(pwmAlvoCascata, 0, 255);
    modoCerracaoAtivo = (pwmAlvoCascata == 255);
    uint8_t alvos[NUM_POSTES];
    for (uint8_t i = 0; i < NUM_POSTES; i++) alvos[i] = (uint8_t)pwmAlvoCascata;
    iniciarAcionamentoCascata(alvos, INTERVALO_CASCATA_MS);
    emitirTelemetriaJson();
    return;
  }

  // 7. Comandos Globais
  if (strcmp(cmd, "OFF") == 0) {
    modoCerracaoAtivo = false;
    cascataEmAndamento = false;
    for (uint8_t i = 0; i < NUM_POSTES; i++) {
      if (pwmAtual[i] != 0 || pwmAlvo[i] != 0) {
        pwmAlvo[i]  = 0;
        pwmAtual[i] = 0;
        aplicarPwmFisico(i, 0);
      }
    }
    emitirTelemetriaJson();
  }
  else if (strcmp(cmd, "ON") == 0 || strcmp(cmd, "NORMAL") == 0) {
    modoCerracaoAtivo = false;
    cascataEmAndamento = false;
    for (uint8_t i = 0; i < NUM_POSTES; i++) {
      if (pwmAtual[i] != 170 || pwmAlvo[i] != 170) {
        pwmAlvo[i]  = 170;
        pwmAtual[i] = 170;
        aplicarPwmFisico(i, 170);
      }
    }
    emitirTelemetriaJson();
  }
  else if (strcmp(cmd, "CERRACAO") == 0) {
    modoCerracaoAtivo = true;
    cascataEmAndamento = false;
    for (uint8_t i = 0; i < NUM_POSTES; i++) {
      if (pwmAtual[i] != 255 || pwmAlvo[i] != 255) {
        pwmAlvo[i]  = 255;
        pwmAtual[i] = 255;
        aplicarPwmFisico(i, 255);
      }
    }
    emitirTelemetriaJson();
  }
  else if (strcmp(cmd, "STATUS") == 0 || strcmp(cmd, "PING") == 0) {
    emitirTelemetriaJson();
  }
}

// Emissão de Telemetria JSON
void emitirTelemetriaJson() {
  Serial.print(F("{\"dispositivo\":\"ARDUINO_MEGA_MESTRE\",\"cascata\":"));
  Serial.print(cascataEmAndamento ? F("true") : F("false"));
  Serial.print(F(",\"ldr\":"));
  Serial.print(valorLdrVirtual);
  Serial.print(F(",\"bateria\":"));
  Serial.print((int)nivelBateriaVirtual);
  Serial.print(F(",\"cerracao\":"));
  Serial.print(modoCerracaoAtivo ? F("true") : F("false"));
  Serial.print(F(",\"pinos\":[2,3,4,5,6],\"torres\":["));
  for (uint8_t i = 0; i < NUM_POSTES; i++) {
    uint8_t pwmReal = (posteEmManutencao[i] || !posteConectado[i]) ? 0 : pwmAlvo[i];
    Serial.print(pwmReal);
    if (i < NUM_POSTES - 1) Serial.print(F(","));
  }
  Serial.println(F("]}"));
}
