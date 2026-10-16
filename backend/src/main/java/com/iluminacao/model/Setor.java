package com.iluminacao.model;

import jakarta.persistence.*;
import lombok.Data;

@Data
@Entity
@Table(name = "setores")
public class Setor {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String nome; // Ex: "Setor Norte - Lavra A"
    
    private String condicaoClimatica; // NORMAL ou CERRACAO
}