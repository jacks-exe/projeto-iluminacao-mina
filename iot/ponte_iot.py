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
# GEONEX SCADA - PONTE IOT ULTRA-RÁPIDA (115200 BPS - HEARTBEAT & WATCHDOG)
# ========================================================================

PORTA_PADRAO = "COM22"
BAUD_RATE = 115200
PORTA_EVENTO_HTTP = 5005
HEARTBEAT_TIMEOUT_S = 6.5

API_PHP_HISTORICO = "http://localhost:8000/historico_telemetria.php"
API_PHP_POSTES = "http://localhost:8000/postes.php"

serial_lock = threading.Lock()
conexao_serial = None
arduino_online = False
porta_atual = PORTA_PADRAO
ultimo_heartbeat_ts = 0.0
ultimo_ping_enviado_ts = 0.0

ultimo_comando_enviado = ""
ultimo_envio_ts = 0.0
ultimo_snapshot_arduino = ""
ultimo_envio_historico_ts = 0.0

# Memória em tempo real do estado e bloqueio de manutenção dos 5 postes
estado_postes = [170, 170, 170, 170, 170]
manutencao_postes = [False, False, False, False, False]


def listar_dispositivos_seriais() -> list:
    try:
        return list(serial.tools.list_ports.comports())
    except Exception:
        return []


def detectar_porta_arduino(porta_preferida: str) -> str:
    """Verifica se a porta preferida existe; caso contrário, detecta automaticamente o Arduino."""
    portas = listar_dispositivos_seriais()
    dispositivos = [p.device for p in portas]
    if porta_preferida in dispositivos:
        return porta_preferida
    for p in portas:
        desc = (p.description or "").lower()
        if "arduino" in desc or "ch340" in desc or "mega" in desc or "usb serial" in desc or "usb-serial" in desc:
            return p.device
    return ""


def notificar_status_arduino_banco(online: bool, motivo: str = ""):
    """Atualiza imediatamente no MySQL (via postes.php) o status Online/Offline do Arduino."""
    def _worker():
        try:
            requests.post(
                API_PHP_POSTES,
                json={
                    "acao": "STATUS_ARDUINO",
                    "online": bool(online),
                    "motivo": motivo,
                },
                timeout=1.2,
            )
        except Exception:
            pass

    threading.Thread(target=_worker, daemon=True).start()


def _fechar_serial_com_seguranca(motivo: str = "Desconexão física ou erro serial"):
    """Trata eventos de 'close' e 'error' da porta serial e marca Arduino como OFFLINE."""
    global conexao_serial, arduino_online
    mudou_para_offline = False

    with serial_lock:
        if conexao_serial is not None:
            try:
                conexao_serial.close()
            except Exception:
                pass
            conexao_serial = None
        if arduino_online:
            arduino_online = False
            mudou_para_offline = True

    if mudou_para_offline:
        print(f"[OFFLINE] Conexao com o Arduino perdida ({motivo}). Atualizando banco e painel...")
        notificar_status_arduino_banco(False, motivo)


def tentar_conectar_serial() -> bool:
    """Tenta abrir a porta serial do Arduino sem encerrar o processo caso o cabo esteja desconectado."""
    global conexao_serial, arduino_online, porta_atual, ultimo_heartbeat_ts

    porta_alvo = detectar_porta_arduino(PORTA_PADRAO)
    if not porta_alvo:
        if arduino_online:
            _fechar_serial_com_seguranca("Porta COM ausente no sistema")
        return False

    try:
        ser = serial.Serial(
            port=porta_alvo,
            baudrate=BAUD_RATE,
            timeout=0.05,
            write_timeout=0.2,
        )
        time.sleep(1.5)
        ser.reset_input_buffer()
        ser.reset_output_buffer()

        with serial_lock:
            conexao_serial = ser
            porta_atual = porta_alvo
            arduino_online = True
            ultimo_heartbeat_ts = time.time()

        print(f"[ONLINE] Conectado ao Arduino na porta {porta_alvo} ({BAUD_RATE} bps)!")
        notificar_status_arduino_banco(True, f"Conectado em {porta_alvo}")
        return True
    except (serial.SerialException, OSError) as e:
        _fechar_serial_com_seguranca(str(e))
        return False
    except Exception as e:
        _fechar_serial_com_seguranca(str(e))
        return False


def enviar_comando_serial_por_evento(comando_bruto: str, origem: str = "EVENTO_CLIQUE") -> bool:
    """
    Envia o comando imediatamente para a porta serial.
    Bloqueia comandos de PWM se o poste estiver em manutenção e detecta erros de cabo desconectado.
    """
    global ultimo_comando_enviado, ultimo_envio_ts, estado_postes, manutencao_postes

    comando_limpo = (comando_bruto or "").strip()
    if not comando_limpo:
        return False

    if not arduino_online or conexao_serial is None:
        return False

    with serial_lock:
        if conexao_serial is None or not conexao_serial.is_open:
            return False

        agora = time.time()
        if comando_limpo == ultimo_comando_enviado and (agora - ultimo_envio_ts) < 0.04:
            return True

        pacote = f"{comando_limpo}\n"

        # 1. Controle da máquina de estados de manutenção: MANUT:id,flag[,pwm_restaurado]
        if comando_limpo.startswith("MANUT:"):
            try:
                partes = comando_limpo[6:].split(",")
                id_p = int(partes[0])
                flag_m = int(partes[1])
                if 1 <= id_p <= 5:
                    manutencao_postes[id_p - 1] = (flag_m == 1)
                    if flag_m == 1:
                        estado_postes[id_p - 1] = 0
                        pacote = f"MANUT:{id_p},1\nPOSTE:{id_p},0\n"
                    else:
                        pwm_restaurado = int(partes[2]) if len(partes) >= 3 else estado_postes[id_p - 1]
                        pwm_restaurado = max(0, min(255, pwm_restaurado))
                        estado_postes[id_p - 1] = pwm_restaurado
                        pacote = f"MANUT:{id_p},0\nPOSTE:{id_p},{pwm_restaurado}\n"
            except (ValueError, IndexError):
                pass

        # 2. Acionamento individual: bloqueia se a torre estiver em manutenção
        elif comando_limpo.startswith("POSTE:"):
            try:
                partes = comando_limpo[6:].split(",")
                id_p = int(partes[0])
                pwm_p = int(partes[1])
                if 1 <= id_p <= 5:
                    if manutencao_postes[id_p - 1]:
                        print(f"[BLOQUEADO] Torre TR-0{id_p} esta EM MANUTENCAO. Comando '{comando_limpo}' ignorado.")
                        return False
                    estado_postes[id_p - 1] = max(0, min(255, pwm_p))
            except (ValueError, IndexError):
                pass

        elif comando_limpo.startswith("SET:"):
            try:
                valores = [int(v.strip()) for v in comando_limpo[4:].split(",") if v.strip()]
                if len(valores) == 5:
                    for i in range(5):
                        if not manutencao_postes[i]:
                            estado_postes[i] = max(0, min(255, valores[i]))
                elif len(valores) == 2 and 1 <= valores[0] <= 5:
                    idx_t = valores[0] - 1
                    if manutencao_postes[idx_t]:
                        return False
                    estado_postes[idx_t] = max(0, min(255, valores[1]))
            except (ValueError, IndexError):
                pass
        elif comando_limpo == "OFF":
            for i in range(5):
                if not manutencao_postes[i]:
                    estado_postes[i] = 0
        elif comando_limpo in ("ON", "NORMAL"):
            for i in range(5):
                if not manutencao_postes[i]:
                    estado_postes[i] = 170
        elif comando_limpo == "CERRACAO":
            for i in range(5):
                if not manutencao_postes[i]:
                    estado_postes[i] = 255
        elif comando_limpo.startswith("TELEM:"):
            try:
                partes = comando_limpo[6:].split(",")
                bat = float(partes[0])
                ldr = int(partes[1])
                alvo = 0 if (bat < 5.0 or ldr >= 450) else (70 if bat < 25.0 else 170)
                for i in range(5):
                    if not manutencao_postes[i]:
                        estado_postes[i] = alvo
                pacote = f"{comando_limpo}\n"
            except (ValueError, IndexError):
                pass

        try:
            conexao_serial.write(pacote.encode("utf-8"))
            conexao_serial.flush()
            ultimo_comando_enviado = comando_limpo
            ultimo_envio_ts = agora
            print(f"[EVENTO -> SERIAL] ({origem}) Enviado: {comando_limpo} | Estado Postes: {estado_postes}")
            return True
        except (serial.SerialException, OSError) as err:
            print(f"[ERRO SERIAL] Queda detectada durante escrita: {err}")
            threading.Thread(target=_fechar_serial_com_seguranca, args=(str(err),), daemon=True).start()
            return False
        except Exception as err:
            print(f"[AVISO] Erro na escrita serial: {err}")
            return False


class ManipuladorEventosBackend(BaseHTTPRequestHandler):
    """
    Endpoint HTTP local ultra-rápido (http://127.0.0.1:5005/evento).
    Retorna o status real de conexão do Arduino (ONLINE / OFFLINE) e envia comandos seriais.
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
        esta_online = bool(arduino_online and conexao_serial is not None and conexao_serial.is_open)
        segundos_sem_heartbeat = round(time.time() - ultimo_heartbeat_ts, 2) if ultimo_heartbeat_ts > 0 else 999.0
        if segundos_sem_heartbeat > HEARTBEAT_TIMEOUT_S:
            esta_online = False

        resposta = json.dumps({
            "sucesso": esta_online,
            "online": esta_online,
            "status": "ONLINE" if esta_online else "OFFLINE",
            "modo": "EVENT_DRIVEN_115200",
            "porta_serial": porta_atual if esta_online else None,
            "baud_rate": BAUD_RATE,
            "ultimo_heartbeat_s": segundos_sem_heartbeat,
            "estado_postes": estado_postes,
            "manutencao_postes": manutencao_postes,
            "ultimo_comando": ultimo_comando_enviado,
        }).encode("utf-8")
        self._enviar_cors(200 if esta_online else 503)
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
                elif acao == "MANUTENCAO" and 1 <= torre_id <= 5:
                    flag_m = 1 if dados.get("em_manutencao") else 0
                    if flag_m == 0 and pwm >= 0:
                        comando = f"MANUT:{torre_id},0,{max(0, min(255, pwm))}"
                    else:
                        comando = f"MANUT:{torre_id},{flag_m}"
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
                "sucesso": enviado,
                "enviado": enviado,
                "online": arduino_online,
                "comando": comando,
                "estado_postes": estado_postes,
            }).encode("utf-8")
            self._enviar_cors(200 if enviado else 409)
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
        print(f"[OK] Listener Event-Driven ativo em http://127.0.0.1:{PORTA_EVENTO_HTTP}/evento")
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


def watchdog_conexao_e_heartbeat():
    """
    Monitora continuamente a presença física da porta USB/Serial e o heartbeat do Arduino.
    Se o cabo for desconectado, altera imediatamente o status no banco e no frontend para Offline.
    """
    global ultimo_ping_enviado_ts
    while True:
        try:
            if arduino_online and conexao_serial is not None:
                # 1. Verifica se a porta COM ainda existe no sistema operacional
                portas_atuais = [p.device for p in listar_dispositivos_seriais()]
                if porta_atual not in portas_atuais or not conexao_serial.is_open:
                    _fechar_serial_com_seguranca(f"Cabo desconectado na porta {porta_atual}")
                else:
                    agora = time.time()
                    sem_sinal = agora - ultimo_heartbeat_ts
                    # 2. Se passou de 3.5s sem telemetria, envia PING de heartbeat
                    if sem_sinal >= 3.5 and (agora - ultimo_ping_enviado_ts) >= 2.0:
                        ultimo_ping_enviado_ts = agora
                        try:
                            with serial_lock:
                                if conexao_serial and conexao_serial.is_open:
                                    conexao_serial.write(b"PING\n")
                                    conexao_serial.flush()
                        except (serial.SerialException, OSError) as err:
                            _fechar_serial_com_seguranca(f"Falha no PING serial: {err}")
                    # 3. Se ultrapassou o timeout máximo de heartbeat sem resposta, declara OFFLINE
                    if (time.time() - ultimo_heartbeat_ts) > HEARTBEAT_TIMEOUT_S:
                        _fechar_serial_com_seguranca("Timeout de heartbeat do Arduino")
            else:
                # Se está offline, verifica se o cabo foi reconectado
                tentar_conectar_serial()
        except Exception:
            pass
        time.sleep(0.8)


def escutar_mega_e_persistir_historico():
    """Lê telemetria e heartbeats do Arduino, capturando imediatamente erros de desconexão."""
    global ultimo_snapshot_arduino, ultimo_envio_historico_ts, estado_postes, ultimo_heartbeat_ts, arduino_online
    while True:
        try:
            if not arduino_online or conexao_serial is None or not conexao_serial.is_open:
                time.sleep(0.2)
                continue

            linha = None
            with serial_lock:
                if conexao_serial and conexao_serial.is_open:
                    aguardando = conexao_serial.in_waiting
                    if aguardando > 0:
                        linha = conexao_serial.readline().decode("utf-8", errors="ignore").strip()

            if linha:
                ultimo_heartbeat_ts = time.time()
                if not arduino_online:
                    arduino_online = True
                    notificar_status_arduino_banco(True, "Heartbeat recebido")

                if linha.startswith("{"):
                    try:
                        dados = json.loads(linha)
                        torres_arr = dados.get("torres", [])
                        if torres_arr and len(torres_arr) == 5:
                            for idx, val in enumerate(torres_arr):
                                if not manutencao_postes[idx]:
                                    estado_postes[idx] = int(val)

                        assinatura = ",".join(str(x) for x in torres_arr)
                        agora = time.time()

                        if assinatura and (assinatura != ultimo_snapshot_arduino or (agora - ultimo_envio_historico_ts) >= 60.0):
                            ultimo_snapshot_arduino = assinatura
                            ultimo_envio_historico_ts = agora
                            threading.Thread(target=_enviar_telemetria_bg, args=(dados,), daemon=True).start()
                            print(f"[TELEMETRIA ARDUINO] PWMs: [{assinatura}]")
                    except json.JSONDecodeError:
                        pass
                else:
                    print(f"[ARDUINO] {linha}")
            else:
                time.sleep(0.015)
        except (serial.SerialException, OSError) as err:
            _fechar_serial_com_seguranca(f"Excecao de leitura serial: {err}")
            time.sleep(0.5)
        except Exception:
            time.sleep(0.1)


if __name__ == "__main__":
    # Inicia servidor HTTP primeiro e tenta conectar ao Arduino (ou marca Offline no banco se desconectado)
    thread_eventos = threading.Thread(target=iniciar_servidor_eventos, daemon=True)
    thread_eventos.start()

    if not tentar_conectar_serial():
        print(f"[AVISO] Arduino nao detectado na porta {PORTA_PADRAO}. Status definido como OFFLINE. Aguardando conexao USB...")
        notificar_status_arduino_banco(False, "Arduino desconectado na inicialização")

    thread_rx = threading.Thread(target=escutar_mega_e_persistir_historico, daemon=True)
    thread_watchdog = threading.Thread(target=watchdog_conexao_e_heartbeat, daemon=True)

    thread_rx.start()
    thread_watchdog.start()

    print(f"[OK] Ponte IoT GEONEX ativa ({BAUD_RATE} bps) com monitoramento de Heartbeat e Desconexao USB.")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\n[INFO] Encerrando Ponte IoT GEONEX...")
        _fechar_serial_com_seguranca("Encerramento manual da Ponte IoT")
