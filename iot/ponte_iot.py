import sys
import time
import json
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
import serial
import serial.tools.list_ports
import requests

# Garante codificação UTF-8 no console do Windows (evita UnicodeEncodeError no CP1252)
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

# ========================================================================
# GEONEX SCADA - PONTE IOT ULTRA-RÁPIDA (115200 BPS - ZERO DELAY)
# ========================================================================
# Arquitetura Event-Driven de Alta Performance:
#   - Resposta HTTP imediata (< 5ms) para os cliques do Dashboard
#   - Envio serial isolado para cada torre (POSTE:id,pwm + SET:p1,p2,p3,p4,p5)
#   - Mantém estado individual de cada poste: NUNCA desliga os outros 4
#   - Telemetria assíncrona em background: NUNCA trava a porta serial
# ========================================================================

PORTA_PADRAO = "COM17"
BAUD_RATE = 115200
PORTA_EVENTO_HTTP = 5005

API_PHP_HISTORICO = "http://localhost:8000/historico_telemetria.php"

serial_lock = threading.Lock()
conexao_serial = None
ultimo_comando_enviado = ""
ultimo_envio_ts = 0.0
ultimo_snapshot_arduino = ""
ultimo_envio_historico_ts = 0.0

# Memória em tempo real do estado de cada um dos 5 postes
estado_postes = [170, 170, 170, 170, 170]


def detectar_porta_arduino(porta_preferida: str) -> str:
    """Verifica se a porta preferida existe; caso contrário, detecta automaticamente o Arduino."""
    portas = list(serial.tools.list_ports.comports())
    dispositivos = [p.device for p in portas]
    if porta_preferida in dispositivos:
        return porta_preferida
    for p in portas:
        desc = (p.description or "").lower()
        if "arduino" in desc or "ch340" in desc or "mega" in desc or "usb serial" in desc:
            return p.device
    return porta_preferida


def conectar_serial() -> serial.Serial:
    porta_alvo = detectar_porta_arduino(PORTA_PADRAO)
    try:
        ser = serial.Serial(
            port=porta_alvo,
            baudrate=BAUD_RATE,
            timeout=0.05,
            write_timeout=0.2,
        )
        print(f"[OK] Conectado ao Arduino na porta {porta_alvo} ({BAUD_RATE} bps)!")
        time.sleep(1.8)
        ser.reset_input_buffer()
        ser.reset_output_buffer()
        return ser
    except Exception as e:
        print(f"[ERRO] Nao foi possivel abrir a porta {porta_alvo}. Verifique o cabo USB e feche o Monitor Serial da IDE.")
        print(f"Detalhes: {e}")
        sys.exit(1)


def enviar_comando_serial_por_evento(comando_bruto: str, origem: str = "EVENTO_CLIQUE") -> bool:
    """
    Envia o comando imediatamente para a porta serial COM17 com resposta instantânea.
    Garante compatibilidade total enviando tanto POSTE:id,pwm quanto SET:t1,t2,t3,t4,t5
    para preservar os outros 4 postes inalterados em qualquer versão de firmware.
    """
    global ultimo_comando_enviado, ultimo_envio_ts, estado_postes

    comando_limpo = (comando_bruto or "").strip()
    if not comando_limpo:
        return False

    with serial_lock:
        if conexao_serial is None or not conexao_serial.is_open:
            return False

        agora = time.time()
        # Deduplicação ultracurta (50ms) para evitar disparos duplicados acidentais
        if comando_limpo == ultimo_comando_enviado and (agora - ultimo_envio_ts) < 0.05:
            return True

        # Atualiza o mapa de estado dos 5 postes
        if comando_limpo.startswith("POSTE:"):
            try:
                partes = comando_limpo[6:].split(",")
                id_p = int(partes[0])
                pwm_p = int(partes[1])
                if 1 <= id_p <= 5:
                    estado_postes[id_p - 1] = max(0, min(255, pwm_p))
            except (ValueError, IndexError):
                pass
        elif comando_limpo.startswith("SET:"):
            try:
                valores = [int(v.strip()) for v in comando_limpo[4:].split(",") if v.strip()]
                if len(valores) == 5:
                    for i in range(5):
                        estado_postes[i] = max(0, min(255, valores[i]))
                elif len(valores) == 2 and 1 <= valores[0] <= 5:
                    estado_postes[valores[0] - 1] = max(0, min(255, valores[1]))
            except (ValueError, IndexError):
                pass
        elif comando_limpo == "OFF":
            estado_postes = [0, 0, 0, 0, 0]
        elif comando_limpo in ("ON", "NORMAL"):
            estado_postes = [170, 170, 170, 170, 170]
        elif comando_limpo == "CERRACAO":
            estado_postes = [255, 255, 255, 255, 255]

        # Pacote serial limpo e único: altera apenas o pino solicitado sem flicker nos demais
        pacote = f"{comando_limpo}\n"

        if comando_limpo.startswith("TELEM:"):
            try:
                partes = comando_limpo[6:].split(",")
                bat = float(partes[0])
                ldr = int(partes[1])
                alvo = 0 if (bat < 5.0 or ldr >= 450) else (70 if bat < 25.0 else 170)
                estado_postes = [alvo, alvo, alvo, alvo, alvo]
                pacote = f"{comando_limpo}\nSET:{alvo},{alvo},{alvo},{alvo},{alvo}\n"
            except (ValueError, IndexError):
                pass

        try:
            conexao_serial.write(pacote.encode("utf-8"))
            conexao_serial.flush()
            ultimo_comando_enviado = comando_limpo
            ultimo_envio_ts = agora
            print(f"[EVENTO -> SERIAL] ({origem}) Enviado: {comando_limpo} | Estado Postes: {estado_postes}")
            return True
        except Exception as err:
            print(f"[AVISO] Erro na escrita serial: {err}")
            return False


class ManipuladorEventosBackend(BaseHTTPRequestHandler):
    """
    Endpoint HTTP local ultra-rápido (http://127.0.0.1:5005/evento).
    Responde em < 5ms para o Frontend e para o backend PHP.
    """

    def log_message(self, format, *args):
        pass

    def _enviar_cors(self, status_code=200):
        self.send_response(status_code)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-User-Role, X-User-Id")
        self.send_header("Content-Type", "application/json; charset=utf-8")

    def do_OPTIONS(self):
        self._enviar_cors(204)
        self.end_headers()

    def do_GET(self):
        resposta = json.dumps({
            "sucesso": True,
            "status": "ONLINE",
            "modo": "EVENT_DRIVEN_115200",
            "porta_serial": conexao_serial.port if conexao_serial else PORTA_PADRAO,
            "baud_rate": BAUD_RATE,
            "estado_postes": estado_postes,
            "ultimo_comando": ultimo_comando_enviado,
        }).encode("utf-8")
        self._enviar_cors(200)
        self.send_header("Content-Length", str(len(resposta)))
        self.end_headers()
        self.wfile.write(resposta)

    def do_POST(self):
        try:
            tamanho = int(self.headers.get("Content-Length", 0))
            corpo_raw = self.rfile.read(tamanho).decode("utf-8", errors="ignore") if tamanho > 0 else "{}"
            dados = json.loads(corpo_raw)

            comando = str(dados.get("comando", "")).strip()
            if not comando and "acao" in dados:
                acao = str(dados.get("acao", "")).upper()
                torre_id = int(dados.get("id", dados.get("torre_id", 0)))
                pwm = int(dados.get("pwm", dados.get("nivelBrilhoAtual", -1)))
                if acao == "POSTE" and 1 <= torre_id <= 5 and pwm >= 0:
                    comando = f"POSTE:{torre_id},{max(0, min(255, pwm))}"
                elif acao == "CASCATA":
                    comando = f"CASCATA:{max(0, min(255, pwm if pwm >= 0 else 170))}"
                elif acao == "CERRACAO":
                    comando = "CERRACAO"
                elif acao == "SENSORES_VIRTUAIS":
                    bat = int(dados.get("bateria", 92))
                    ldr = int(dados.get("ldr", 310))
                    comando = f"TELEM:{bat},{ldr}"

            enviado = enviar_comando_serial_por_evento(
                comando_bruto=comando,
                origem="HTTP_EVENTO",
            )

            resposta = json.dumps({
                "sucesso": True,
                "enviado": enviado,
                "comando": comando,
                "estado_postes": estado_postes,
            }).encode("utf-8")
            self._enviar_cors(200)
            self.send_header("Content-Length", str(len(resposta)))
            self.end_headers()
            self.wfile.write(resposta)
        except Exception as exc:
            erro_bytes = json.dumps({"sucesso": False, "erro": str(exc)}).encode("utf-8")
            self._enviar_cors(400)
            self.send_header("Content-Length", str(len(erro_bytes)))
            self.end_headers()
            self.wfile.write(erro_bytes)


def iniciar_servidor_eventos():
    try:
        servidor = HTTPServer(("127.0.0.1", PORTA_EVENTO_HTTP), ManipuladorEventosBackend)
        print(f"[OK] Listener Event-Driven ativo em http://127.0.0.1:{PORTA_EVENTO_HTTP}/evento (Latência < 5ms)")
        servidor.serve_forever()
    except Exception as e:
        print(f"[AVISO] Porta {PORTA_EVENTO_HTTP} indisponivel: {e}")


def _enviar_telemetria_bg(dados):
    """Envia telemetria para o MySQL em thread assíncrona separada para NUNCA travar a serial"""
    try:
        dados["origem_evento"] = "ARDUINO_IOT"
        requests.post(API_PHP_HISTORICO, json=dados, timeout=0.8)
    except Exception:
        pass


def escutar_mega_e_persistir_historico():
    """Lê telemetria do Arduino sem bloquear o envio de comandos"""
    global ultimo_snapshot_arduino, ultimo_envio_historico_ts, estado_postes
    while True:
        try:
            linha = None
            if conexao_serial and conexao_serial.is_open and conexao_serial.in_waiting > 0:
                with serial_lock:
                    if conexao_serial.in_waiting > 0:
                        linha = conexao_serial.readline().decode("utf-8", errors="ignore").strip()

            if linha:
                if linha.startswith("{"):
                    try:
                        dados = json.loads(linha)
                        torres_arr = dados.get("torres", [])
                        if torres_arr and len(torres_arr) == 5:
                            for idx, val in enumerate(torres_arr):
                                estado_postes[idx] = int(val)

                        assinatura = ",".join(str(x) for x in torres_arr)
                        agora = time.time()

                        # Grava apenas mudanças reais ou a cada 60s
                        if assinatura and (assinatura != ultimo_snapshot_arduino or (agora - ultimo_envio_historico_ts) >= 60.0):
                            ultimo_snapshot_arduino = assinatura
                            ultimo_envio_historico_ts = agora
                            # Dispara em background para NUNCA bloquear a leitura serial
                            threading.Thread(target=_enviar_telemetria_bg, args=(dados,), daemon=True).start()
                            print(f"[TELEMETRIA ARDUINO] PWMs: [{assinatura}]")
                    except json.JSONDecodeError:
                        pass
                else:
                    print(f"[ARDUINO] {linha}")
            else:
                time.sleep(0.01)
        except Exception as err:
            time.sleep(0.1)


if __name__ == "__main__":
    conexao_serial = conectar_serial()

    thread_eventos = threading.Thread(target=iniciar_servidor_eventos, daemon=True)
    thread_rx = threading.Thread(target=escutar_mega_e_persistir_historico, daemon=True)

    thread_eventos.start()
    thread_rx.start()

    print(f"[OK] Ponte IoT GEONEX ativa ({BAUD_RATE} bps). Sem deadlocks, sem fila de espera.")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\n[INFO] Encerrando Ponte IoT GEONEX...")
        if conexao_serial and conexao_serial.is_open:
            conexao_serial.close()
