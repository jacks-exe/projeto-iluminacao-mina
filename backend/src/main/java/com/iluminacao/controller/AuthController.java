package com.iluminacao.controller;

import com.iluminacao.dto.LoginRequest;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import java.util.Locale;
import java.util.Map;
import java.util.Set;

@RestController
@RequestMapping("/api/auth")
@CrossOrigin(origins = "*")
public class AuthController {

    private static final Set<String> SENHAS_PADRAO = Set.of("123456", "geonex123");

    @PostMapping("/login")
    public ResponseEntity<?> login(@RequestBody LoginRequest request) {
        if (request == null || request.getUsername() == null || request.getPassword() == null) {
            return ResponseEntity.status(HttpStatus.BAD_REQUEST).body(Map.of(
                    "status", "error",
                    "message", "Preencha usuário e senha."
            ));
        }

        String user = request.getUsername().trim().toLowerCase(Locale.ROOT);
        String pass = request.getPassword().trim();

        // 1. Nível de Acesso: Administrador (adm)
        if ((user.equals("adm") || user.equals("admin") || user.equals("administrador") || user.equals("adm-001"))
                && (SENHAS_PADRAO.contains(pass) || pass.equals("adm") || pass.equals("admin") || pass.equals("adm123"))) {
            return ResponseEntity.ok(Map.of(
                    "status", "success",
                    "message", "Login de Administrador realizado com sucesso",
                    "id", 1,
                    "username", "adm",
                    "nome", "Eng. Carlos Mendes",
                    "cargo", "Administrador Geral",
                    "role", "ADMIN"
            ));
        }

        // 2. Nível de Acesso: Técnico
        if ((user.equals("tecnico") || user.equals("técnico") || user.equals("tec") || user.equals("tec-208") || user.equals("tec-215"))
                && (SENHAS_PADRAO.contains(pass) || pass.equals("tecnico") || pass.equals("tec") || pass.equals("tec123"))) {
            return ResponseEntity.ok(Map.of(
                    "status", "success",
                    "message", "Login de Técnico realizado com sucesso",
                    "id", 3,
                    "username", "tecnico",
                    "nome", "Roberto Lima",
                    "cargo", "Técnico de Manutenção",
                    "role", "TECNICO"
            ));
        }

        // 3. Nível de Acesso: Operador
        if ((user.equals("operador") || user.equals("opr") || user.equals("op") || user.equals("opr-104"))
                && (SENHAS_PADRAO.contains(pass) || pass.equals("operador") || pass.equals("opr") || pass.equals("op123"))) {
            return ResponseEntity.ok(Map.of(
                    "status", "success",
                    "message", "Login de Operador realizado com sucesso",
                    "id", 2,
                    "username", "operador",
                    "nome", "Marcos Souza (CCO)",
                    "cargo", "Operador de Mina",
                    "role", "OPERADOR"
            ));
        }

        return ResponseEntity.status(HttpStatus.UNAUTHORIZED).body(Map.of(
                "status", "error",
                "message", "Usuário ou senha incorretos."
        ));
    }

    @PostMapping("/logout")
    public ResponseEntity<?> logout() {
        return ResponseEntity.ok(Map.of(
                "status", "success",
                "message", "Sessão encerrada com sucesso."
        ));
    }
}
