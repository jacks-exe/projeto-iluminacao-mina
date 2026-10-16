package com.iluminacao.model;

import jakarta.persistence.*;
import lombok.Data;

@Data
@Entity
@Table(name = "torres")
public class Torre {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String codigo; // Ex: "TR-01"
    
    private int pinoPwm; // Ex: 3, 5, 6, 9, 10 (Pinos do Arduino Uno)
    
    private String modoIluminacao; // DESLIGADA, ECONOMIA, NORMAL, CERRACAO
    
    private int nivelBrilhoAtual; // 0 a 255 (Enviado para o Python)

    private String statusOperacional; // OPERANDO ou EM_MANUTENCAO

    @ManyToOne
    @JoinColumn(name = "setor_id")
    private Setor setor; // A qual rua essa torre pertence
}