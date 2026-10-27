@echo off
echo Iniciando o ambiente do projeto Geonex...

:: 1. Iniciar o Docker Desktop
echo Iniciando Docker Desktop...
start "" "C:\Users\Evolabs\AppData\Local\Programs\DockerDesktop\Docker Desktop.exe"

:: Pausa para dar tempo do Docker carregar antes de subir o Spring Boot (ajuste o tempo se seu PC for mais rápido/lento)
timeout /t 30 /nobreak

:: 2. Iniciar Frontend (Terminal 1)
echo Iniciando Frontend...
start "Frontend Geonex" cmd /k "cd /d C:\projeto-iluminacao-mina\frontend && npm run dev"

:: 3. Iniciar Backend Spring Boot (Terminal 2)
echo Iniciando Spring Boot...
start "Backend Spring" cmd /k "cd /d C:\projeto-iluminacao-mina\backend && mvn spring-boot:run"

:: 4. Iniciar Backend PHP (Terminal 3 - Multithread com 4 Workers)
echo Iniciando PHP (Multithread)...
start "Backend PHP" cmd /k "cd /d C:\projeto-iluminacao-mina\backend-php && set PHP_CLI_SERVER_WORKERS=4 && C:\xampp\php\php.exe -S localhost:8000"

:: 5. Iniciar IoT (Terminal 4)
echo Iniciando Python IoT...
start "IoT Bridge" cmd /k "cd /d C:\projeto-iluminacao-mina\iot && if exist C:\Users\Evolabs\AppData\Local\Python\pythoncore-3.14-64\python.exe (C:\Users\Evolabs\AppData\Local\Python\pythoncore-3.14-64\python.exe -u ponte_iot.py) else (py -u ponte_iot.py)"

:: Pausa para os servidores terminarem de compilar e iniciar antes de abrir o navegador
timeout /t 15 /nobreak

:: 6. Abrir o navegador no Localhost
:: Substitua a porta abaixo (3000, 5173, 8080) pela porta correta que o seu "npm run dev" gera
start http://localhost:5173

echo Todos os servicos foram iniciados!
