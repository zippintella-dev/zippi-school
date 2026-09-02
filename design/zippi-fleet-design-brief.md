# Zippi Fleet — design brief

> Paste this whole file into Claude Design. It is self-contained: everything
> needed to design the screens is here, including the existing design system so
> Fleet matches the Zippi Parent app already built.

---

## What this is

**Zippi Fleet** is the vehicle app for an Indian school bus service. Two people
use it on two separate devices during the same trip:

- **The attendant** — rides with the children. Marks each child boarded, verifies
  who collects them at the drop stop, sweeps the bus. This is the safety role.
- **The driver** — drives. Navigation, head count, speed, SOS. **Cannot mark
  children.**

One app, two role modes. The trip is shared state — both devices see the same
trip, and either can start it.

It pairs with **Zippi Parent** (already built) and the **Zippi School** dashboard.
When the attendant marks a child boarded, that child's parents see it within
seconds.

---

## Who is holding the phone, and where

Design for this, not for a desk:

- **One hand.** The other is steadying a child or holding a rail.
- **A moving vehicle.** Bumpy, and the user is often standing.
- **Direct sunlight**, through a bus window, in Hyderabad.
- **Under time pressure.** 22 children board in about 90 seconds while traffic
  waits behind the bus.
- **Patchy signal.** The route passes through dead zones.
- Users are not office workers. Assume a mid-range Android phone, a
  first-language that may not be English, and no training beyond one session.

The single most common error is **tapping the adjacent row and marking the wrong
child**. Much of the design exists to make that hard.

---

## Design system — match the Parent app exactly

**Colours**

| Token | Hex | Use |
|---|---|---|
| Turquoise | `#40E0D0` | Primary action, anything live. Pressed: `#2BC9B8` |
| On-turquoise | `#123F3A` | Text on turquoise (dark, not white) |
| Teal | `#0E7C72` | Wordmark, teal-on-cream text |
| Teal soft | `#E0FAF7` / border `#A9F0E8` | Live banners, selected chips |
| Coral | `#FF8070` | Secondary action, "this needs you now". Pressed `#FF6A57` |
| On-coral | `#4A1710` | Text on coral |
| Coral text | `#E2503C` | Coral text on cream |
| Coral soft | `#FFE9E2` | Coral chip background |
| Background | `#FFF9F2` | The page — cream, never grey |
| Surface | `#FFFFFF` | Cards |
| Card border | `#F0E4D6` (1.5px) | Card outline — warm |
| Divider | `#F5EDE1` | Inside a card |
| Ink | `#15212B` · Muted `#64748B` · Faint `#94A3B8` | Text |
| Green | `#2E7D32` bg `#E9F5EA` | Done, on time |
| Amber | `#B26A00` bg `#FFF4E0` | Warning, running late |
| Red | `#C62828` bg `#FDECEC` | Danger, SOS, blocked |

**Type** — Quicksand (600/700) for headings, numbers and buttons. Mulish
(400/600/700/800) for body.

**Shape** — cards 24px radius, 1.5px warm border, no shadow. Buttons are full
pills (999px), 56px tall. Inputs 16px radius, 56px tall. Chips 999px, 44px tall.

**Sizing rules that are not negotiable**

- **56dp minimum** for any row or control that changes a child's state.
- 44dp minimum for everything else.
- 16px minimum font in any text input.
- Status is conveyed by **colour AND words**, never colour alone.

---

## Safety rules that shape the UI

These are not preferences. They come from four invariants the whole product is
built around, and a design that breaks one is wrong.

1. **A child is never released without a verified receiver.** There is no
   "skip child", no "leave child", no "mark absent and drive on" at a drop stop
   — **at any point, for any role, in any build**. If nobody can be verified,
   the only remaining action is *Return to school*.
2. **A trip cannot be completed while a child is unaccounted for.** The complete
   action fails and names them.
3. **The bus is physically swept before the trip closes.** Timestamped,
   geo-stamped, photo-backed. Cannot be skipped or pre-tapped.
4. **Every override is audited.**

Also:

- **Begin Trip is a swipe, not a button.** Released past 85% travel it fires;
  less springs back. An accidental start pushes "the bus has left" to 22 families.
- **The driver device has no child-marking controls at all.** A driver marking
  children is a driver looking at a phone while children board around the vehicle.
- **SOS is a 2-second long-press with haptic confirmation**, never a tap.

---

## Screens to design

Design **portrait phone**, 428×908 or similar. Where states are listed, please
draw each as its own artboard.

### Shared

**1 · Login**
Phone number → 4-digit OTP. Same pattern as the Parent app: `+91` prefix affix,
numeric keypad, OTP as 4 separate boxes. Show the resend as a live countdown.

**2 · Role & vehicle**
One phone can be an attendant on one bus and a driver on another, and can be a
parent too. After login, if more than one role exists, pick: role (Driver /
Attendant), bus, route. Show today's assignment as the obvious default.

**3 · Duty dashboard — today's trips**
Read-only stack of the day's trips for this vehicle. **Trips cannot be started
from here** — tapping opens the trip. Active trip pinned to the top.

| Status | Opacity | Pill |
|---|---|---|
| Running | 1.0 | **Now running** (teal) |
| Up next | 0.65 | Up next |
| Done | 0.45 | Done |
| Cancelled / Moved | 0.45 | Cancelled / Moved |

Each card: route code + name, direction, bell tier, scheduled start, child count,
stop count, bell time, and a "Leave in 18 min" / "Leave now" chip that turns
coral at 2 minutes before departure.

---

### Attendant

**4 · Pre-trip checklist — blocking**
Cannot proceed until all ticked. Vehicle clean and seats intact · First aid box
present · Fire extinguisher present · Emergency exit clear. Each tick is
timestamped into the trip record. Then the **swipe-to-begin** widget.

**5 · Trip flow — stop list** *(the home screen of a running trip)*
- Header: **"18 of 22 on board"** — big, glanceable.
- Stops in authored sequence, never re-ordered. A **NEXT** pill on the first
  unvisited stop.
- Per stop: name, scheduled time, child count, reached/not reached.
- Navigation icon that hands off to Google Maps.
- **SOS button always visible.**
- States: nothing reached yet · mid-route · all stops done.

**6 · At stop — the child list** *(the most important screen in the app)*
Reached via tapping a stop; geo-fenced to 150m.
- Each child is a **56dp minimum** row with **a photo**, name, and class.
- Tap to mark boarded — row turns green, header count increments.
- **Siblings must be visually distinguishable** (same surname, similar face on a
  small photo) — show class and a distinguishing detail.
- A **90-second undo** for mis-taps, shown as a countdown on the row.
- A **wait countdown** (120s default) runs from arrival. Only after it expires
  does **"Not at stop"** unlock per child — and **morning only**.
- "Depart stop" ends the stop.
- States: fresh arrival · some boarded · countdown expired with "Not at stop"
  available · all resolved.

**7 · Head-count reconciliation** *(after the last stop, morning)*
> "You marked 18 children boarded. Count the children on the bus."

A number entry. On mismatch, list the boarded children with photos for a visual
recheck before the trip may proceed. This catches the wrong-row error class.

**8 · At school — arrival** *(morning)*
Geo-fenced to the school gate. Confirm disembark → every boarded child becomes
"arrived at school", and all guardians are notified.

**9 · Boarding at school** *(afternoon — the mirror of screen 6)*
- Children grouped **by class**, since they come out class by class.
- ☐ NOT BOARDED / ✓ BOARDED per child.
- Absent children greyed with their reason. "Parent collecting" rows in blue.
- Countdown to scheduled departure.
- **[Lock in & depart]** — enabled when either every child is boarded/absent, or
  the departure time is reached. Un-boarded children are marked absent and **the
  school office is notified**, because an expected child who did not board is
  the school's problem to resolve before the bus leaves.

**10 · Drop stop — per child** *(afternoon; this is the core safety screen)*
For each child at the stop, exactly one of:
- **Verify handover code** → 4-digit keypad. 5 wrong attempts locks it.
- **Pick authorized person** → a grid of faces with name and relationship. Used
  when the regular grandmother collects daily and typing a code four times a
  week is friction that gets worked around.
- **Self-release** → only shown when the child has signed consent *and* meets the
  school's minimum grade. Otherwise not rendered at all.
- **No one here** → starts the escalation ladder.

**There is no skip. There is no "leave child".** Design the screen so that
absence is conspicuous.

**11 · Escalation ladder** *(when nobody can be verified)*
A timeline the attendant watches, with a live countdown:

```
T+0:00  "Nobody is here for Aarav." Guardians notified. Bus waits until 4:26 PM.
T+1:30  Calling guardian 1…        (number is masked — never shown)
T+2:30  Calling guardian 2…
T+3:00  Countdown expires →  RETURN TO SCHOOL  is the only action left
```

Design the expiry state: every other option gone, one coral action remaining.

**12 · Vehicle sweep — blocking**
> "Walk to the back of the bus. Confirm every seat is empty."

Camera capture, geo-stamped, timestamped. Cannot be skipped, cannot be pre-tapped
before the bus reaches the school. Design the blocked state (before arrival) and
the ready state.

**13 · Trip complete**
Summary: boarded, handed over, absent, returned to school. If a child is still
unaccounted for, this **fails** and names them — design that error state, it is
the invariant made visible.

---

### Driver

**14 · Navigation-first screen** *(the driver's only trip screen)*
- Big map, next stop, turn-by-turn handoff to Google Maps.
- **"18 on board · next: Silver Oak"** strip.
- **Speed readout that turns red above the school-bus limit** (40 km/h default).
- SOS button.
- **No child-marking controls anywhere.** This is the point of the role split.

---

### Both roles

**15 · SOS**
- 2-second long-press with haptic confirmation, never a tap.
- Type picker: accident · breakdown · medical · security · fire · other.
- After firing: a locked state — **no further child state changes are accepted**
  until ops resolves or releases it, preventing a panicked mis-tap sequence.
- Show that the school and control room have been alerted and are responding.
- Design the **drill** variant, clearly banded "THIS IS A DRILL".

---

## Also worth designing

- **Offline banner.** Handover codes verify from a cache in a dead zone; the
  event syncs later. The attendant needs to know they are offline without being
  alarmed — the app still works.
- **Empty and error states** for each list.
- The **already-running-elsewhere** state: only one trip per bus may be started
  at a time.

---

## Tone

Calm, plain, unhurried. Short sentences. No jargon, no exclamation marks. When
something is blocked, say **why** and what to do next — "Cannot complete: Aarav
is still on board" beats "Error". The people using this are responsible for
other people's children on a Tuesday morning, and the interface should feel like
it is helping rather than testing them.
