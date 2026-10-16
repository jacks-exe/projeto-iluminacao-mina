import serial
import requests
import time
import json
import threading

# ========================================================================
# CONFIGURAÇÕES: AJUSTE AQUI ANTES DE RODAR!
# ========================================================================
PORTA_COM_DO_MEGA = "COM3"  # <--- MUDE ISSO PARA A PORTA USB DO SEU MEGA NA ARDUINO IDE
BAUD_RATE = 115200

# Endereços da sua API Spring Boot (Ajuste as URLs conforme a sua equipe programou)
API_TELEMETRIA = "http://localhost:8080/api/iot/telemetria"
API_LER_ESTADO = "http://localhost:8080/api/iot/estado"

try:
    conexao_serial = serial.Serial(PORTA_COM_DO_MEGA, BAUD_RATE, timeout=1)
    print(f"✅ Conectado ao Arduino Mega na porta {PORTA_COM_DO_MEGA}!")
except Exception as e:
    print(f"❌ Erro ao abrir a porta {PORTA_COM_DO_MEGA}. O Arduino está conectado e a IDE está fechada?")
    print(e)
    exit()

def escutar_arduino_e_enviar_pro_springboot():
    """Lê o JSON gerado pelo Arduino Mega e manda via POST para a API Java"""
    while True:
        if conexao_serial.in_waiting > 0:
            linha = conexao_serial.readline().decode('utf-8', errors='ignore').strip()
            
            # Procura por linhas que começam com "{" (O JSON de telemetria)
            if linha.startswith("{"):
                try:
                    dados = json.loads(linha)
                    print("\n📡 Recebido do Arduino:", dados)
                    
                    # Exemplo: O botão de Cerração foi apertado na maquete!
                    # O Python avisa o Spring Boot via POST
                    if dados.get("cerracao") == True:
                        print("🚨 Alerta Físico: Enviando POST para o Backend!")
                        payload = {"evento": "CERRACAO_FISICA", "ativo": True}
                        requests.post(API_TELEMETRIA, json=payload)
                        
                except json.JSONDecodeError:
                    print(f"⚠️ Erro ao ler JSON: {linha}")
                except requests.exceptions.RequestException as e:
                    print(f"⚠️ Erro ao contatar a API Spring Boot: {e}")
            else:
                print(f"[LOG ARDUINO] {linha}")

def escutar_springboot_e_enviar_pro_arduino():
    """Faz polling no Spring Boot e, se o Vue.js mudou algo, avisa o Arduino Mega"""
    while True:
        try:
            # Exemplo: a API retorna {"pwm_torres": "255,170,0,170,70"}
            resposta = requests.get(API_LER_ESTADO)
            if resposta.status_code == 200:
                dados = resposta.json()
                comando = f"SET:{dados['pwm_torres']}\n"
                conexao_serial.write(comando.encode('utf-8'))
                print(f"💻 Comando da Web enviado pro Arduino: {comando.strip()}")
        except requests.exceptions.RequestException:
            pass # Ignora erro silenciosamente se o Java estiver desligado
            
        time.sleep(1) # Faz a verificação a cada 1 segundo

# Inicia as duas tarefas ao mesmo tempo
thread_arduino = threading.Thread(target=escutar_arduino_e_enviar_pro_springboot, daemon=True)
thread_java = threading.Thread(target=escutar_springboot_e_enviar_pro_arduino, daemon=True)

thread_arduino.start()
thread_java.start()

print("🚀 Ponte Rodando! Aperte CTRL+C para sair.")
while True:
    time.sleep(1)