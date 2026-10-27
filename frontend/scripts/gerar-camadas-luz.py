"""
Gera as camadas de luz do Digital Twin a partir dos renders originais.

Entrada  (frontend/public/assets/):
  mina-base.png          render com TODAS as luzes apagadas (RGBA; fundo translúcido)
  luz-torre-{1..5}.png   render com SOMENTE a torre N acesa

Saída (mesma pasta):
  luz-fx-{1..5}.png      feixe isolado da torre N: RGBA, 100% transparente fora da luz

Por quê: os `luz-torre-N.png` são frames completos (maquete + fundo) com alfa ~15% constante.
Sobrepô-los clareia a cena inteira e a soma das 5 camadas fica bem mais clara que o render
"full" real. Aqui extraímos apenas a contribuição de luz (layer - base), ponderada pela
cobertura da maquete, e aplicamos um ganho para que 5 torres a 100% (blend `screen`) não
estourem a cena.

Uso:
  pip install pillow numpy
  python frontend/scripts/gerar-camadas-luz.py
"""

from pathlib import Path

import numpy as np
from PIL import Image

ASSETS = Path(__file__).resolve().parent.parent / "public" / "assets"
TOTAL_TORRES = 5
GANHO = 0.85  # intensidade do feixe a 100% de PWM
NOISE_FLOOR = 3 / 255  # ruído de quantização 8-bit


def load_rgba(name: str) -> tuple[np.ndarray, np.ndarray]:
    arr = np.asarray(Image.open(ASSETS / name).convert("RGBA"), dtype=np.float32) / 255.0
    return arr[..., :3], arr[..., 3:4]


def main() -> None:
    base, cover = load_rgba("mina-base.png")

    for n in range(1, TOTAL_TORRES + 1):
        layer, _ = load_rgba(f"luz-torre-{n}.png")
        # contribuição de luz da torre N, ponderada pela cobertura da maquete
        d = np.clip((layer - base - NOISE_FLOOR) * GANHO, 0.0, 1.0) * cover

        alpha = d.max(axis=2, keepdims=True)
        color = np.clip(d / np.where(alpha > 1e-4, alpha, 1.0), 0.0, 1.0)  # não pré-multiplicada
        rgba = np.concatenate([color, alpha], axis=2)

        out = ASSETS / f"luz-fx-{n}.png"
        Image.fromarray((rgba * 255).round().astype(np.uint8), "RGBA").save(out, optimize=True)
        print(f"{out.name}: alpha max={alpha.max():.2f}")


if __name__ == "__main__":
    main()
