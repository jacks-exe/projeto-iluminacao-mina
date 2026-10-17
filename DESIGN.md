# Design System: Mina IoT — Controle de Iluminação (SCADA)
**Project ID:** local / sem projeto Stitch vinculado

## 1. Visual Theme & Atmosphere
Industrial Dark Modern / SCADA High-Tech. A dense, utilitarian control-room interface built for
long night shifts: deep graphite surfaces that keep the operator's eyes adapted to darkness,
punctuated by warm ore-and-earth accents that only "light up" where action or attention is
required. Information density is high but ordered — every element sits on a strict 4px grid,
labels are uppercase micro-type with generous tracking, and live numeric readouts use a
monospaced face so values never "jump" while polling.

## 2. Color Palette & Roles
| Token | Name | Hex | Role |
|---|---|---|---|
| `abyss` | Abyss Graphite | `#0B0E14` | Page background, deepest layer |
| `panel` | Control-Panel Graphite | `#12161F` | Cards, dock, header surfaces |
| `panel-2` | Raised Graphite | `#181D28` | Inputs, hovered rows, inner wells |
| `line` | Hairline Steel | `#232A37` | 1px borders and dividers |
| `ore` | Molten Amber Ore | `#D97706` | Primary accent, active selectors, focus rings |
| `ember` | Blast-Furnace Ember | `#EA580C` | Critical / contingency actions (Cerração Geral) |
| `clay` | Laterite Clay | `#C68B59` | Secondary accent, labels, tower codes |
| `ink` | Arc-Light White | `#F8F9FA` | Primary text and numeric readouts |
| `mute` | Dust Gray | `#9CA3AF` | Secondary text, captions |
| `ok` | Signal Green | `#22C55E` | Connection online, tower operating |
| `fault` | Alarm Red | `#EF4444` | Offline, command failure |

Mode semantics (PWM): OFF `#4B5563` · ECO `#C68B59` · NORM `#D97706` · CERR `#EA580C`.

## 3. Typography Rules
* **Inter** (400/500/600/700) for UI text. Section labels: 10–11px, uppercase, `tracking-[0.18em]`, Dust Gray.
* **JetBrains Mono** (500/600) for every live value: PWM, %, kW, timestamps, tower codes. Tabular numbers.
* Headline (system title): 15px semibold, Arc-Light White, slight tracking.

## 4. Component Stylings
* **Buttons:** Sharp-to-subtly rounded (6px). Segmented selectors use a recessed well (`panel-2`) with the active segment filled by the mode color and a soft outer glow of the same hue. Contingency button is Ember, full-bleed, with a diagonal hazard stripe on active state.
* **Cards/Containers:** 10px radius, `panel` background, 1px `line` border, no drop shadow — elevation is expressed by border luminance and inner glow, never by heavy shadows.
* **Inputs/Forms:** `panel-2` fill, 1px `line` stroke, Ore focus ring (1px) — no browser default outlines.
* **Status badges:** Pill-shaped, 1px tinted border, 10% tinted fill, leading pulsing dot.
* **Tower nodes:** Circular beacon with concentric ping ring whose color and speed reflect the mode.

## 5. Layout Principles
Full-viewport grid, no page scroll on ≥1280px. Header (56px) → body split into a 320px left rail
(maintenance + energy) and a fluid Digital Twin viewport; a fixed dock spans the bottom of the
viewport column. Gutter 12px, inner padding 16px. Corner brackets frame the twin viewport to
evoke a sensor/camera feed.
