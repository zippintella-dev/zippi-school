# Zippi Parent — native mobile app requirements

> Layer 1 of the four-layer platform. Requirements for the **native** app.
> Companion to [`school_mobility_rebuild.md`](school_mobility_rebuild.md); every
> `PART x` reference below points into that spec.

---

## 0. Status and what this document is for

A **mobile web app (PWA)** for Zippi Parent already exists and works, served
from the Laravel install at `/parent`. It covers the full PART H4 screen set,
OTP login, the live-map discipline, the handover code and absence marking, and
it installs to a phone home screen.

This document specifies the **native app** that replaces it, and states plainly
what native buys that the PWA cannot deliver.

The PWA is not throwaway. It has already:

- proved the domain logic end to end (auth → dashboard → live → journey),
- settled the exact payload the app needs (`GET /parent/child/{id}/data`),
- validated the safety rules — the L29 map-closing discipline, K9 masking,
  the A7 handover code — against real generated trips.

The native app consumes the same shapes over a token API instead of a session.

---

## 1. Why native (the honest list)

Native is justified by exactly four things. Everything else on these screens the
PWA already does.

| # | Capability | Why the PWA can't | Severity |
|---|---|---|---|
| 1 | **Reliable push** (PART F1, 15 event types) | iOS web push needs iOS 16.4+, needs the user to have installed the PWA first, and silently stops if they delete it. For the safety set — child boarded, arrived, handed over, nobody at stop, returning to school, SOS — best-effort is not good enough. | **Blocking** |
| 2 | **App store presence** | Parents look for "Zippi" in the store. A URL handed out on a school circular converts far worse, and a school selling this to 400 families needs a listing to point at. | **High** |
| 3 | **Native maps** | Google Maps SDK with real tiles and traffic, versus the current schematic plot. PART F3's approach alerts are computed server-side either way, but the map itself is what a parent stares at. | **High** |
| 4 | **Reliable background delivery** | An SOS (PART Q) or a "nobody at the stop" (A7) must reach a locked phone. | **Blocking** |

**What native does NOT buy here:** offline operation (see §7), background
location (the parent's phone is never tracked — only the bus is), or any change
to the business rules. The rules live on the server and must stay there.

---

## 2. Platform

**Recommendation: React Native (Expo, bare workflow when native modules are
needed).**

| Option | For | Against |
|---|---|---|
| **React Native + Expo** ✅ | One codebase for iOS + Android; Expo handles push credentials, builds and OTA updates; large hiring pool in Hyderabad; Node 22 already on the build machine. | Native module work needs the bare workflow. |
| Flutter | Excellent map/list performance; single codebase. | No existing precedent in this stack; smaller local hiring pool for maintenance. |
| Native Kotlin + Swift | Best possible fidelity. The enterprise source material was native Kotlin (`FamilyDashboardActivity.kt`). | Two codebases, and Zippi Fleet needs a third and fourth. Not justified at pilot scale. |

**Decision driver:** Zippi Fleet (Layer 3) is coming and is a *bigger* app than
this one. Whatever is chosen here will be used twice. Choose for the pair.

### Minimum device support

- **iOS 15+**, iPhone 8 and newer.
- **Android 8.0 (API 26)+**. Non-negotiable at the low end — a meaningful share
  of Indian school parents are on sub-₹12,000 Android devices, and the app must
  be usable on a 720p screen with 2 GB RAM.
- Portrait only. Tablet layout is out of scope for Phase 1.
- **Test on a real low-end Android**, not only a simulator. The exception queue
  and the 10-second poll are where cheap devices fall over.

---

## 3. Server work required first (this does not exist yet)

The PWA is **session-authenticated Blade**. A native app needs a token API, and
none of it is built.

| Item | Detail | Spec |
|---|---|---|
| `routes/api.php` | Does not exist. The install is web-only today. | — |
| **Laravel Sanctum** | Not installed. No `personal_access_tokens` table. | P7 |
| **Token issue on OTP verify** | `OtpService` exists and is correct; it currently ends in a session login. Needs to return a bearer token instead. | P1–P4 |
| **`role_links` / multi-role identity** | Not modelled. One phone is legitimately a parent at School A, a parent at School B, **and** an attendant. Login must present a role/school picker when more than one link exists. Do not block registration on cross-role phone collisions. | **P6** |
| **`GET /family-dashboard`** | The polled endpoint the spec names. `/parent/child/{id}/data` already returns the right shape for one child; this is the multi-child version. | F6 |
| **FCM fan-out** | `SchoolNotificationDispatcher` + one notification class per event. Single fan-out point — no controller sends to a guardian directly. | F1, F7 |
| **SMS gateway** | Not wired. OTP codes currently go to the log. | P2 |
| **Device token registration** | `POST /device-token`. `Guardian::claimFcmToken()` already exists and correctly strips the token from every other guardian first (L10). | L10 |

### The API error contract is not optional (PART P7)

Every non-2xx carries a `message` the app **renders verbatim**:

```
OTP verify OK        200 {status, message, user:{...}, roles:[...], token}
Wrong OTP            400 {status:false, message, attempts_remaining:N}
Locked               429 {status:false, message}
Send throttled       429 {status:false, message}
```

> An app that clears the input and shows nothing on a non-2xx is a **defect,
> not a cosmetic issue**. `OtpService` already returns exactly these fields.

---

## 4. Screens (PART H4)

```
[Splash] → [Login — mobile OTP] → [Family Dashboard]
                                          │
                                    tap a child
                                          ▼
                                   [Child Live]
                                          │
                                   [Journey] tab
```

### 4.1 Login

- Phone number field, `tel` keyboard, autofill from the address book.
- OTP field must accept the **SMS autofill** (`one-time-code` on iOS, SMS
  Retriever API on Android). This is the difference between a 3-second login and
  a parent switching apps to copy a code.
- Resend, with the P4 throttle surfaced as a live countdown, not an error.
- **An unknown number must not be distinguishable from a known one.** Telling a
  stranger which numbers are parents at a given school is a child-safety leak.
  Divergence happens only *after* a correct code, into a dead end.
- Role/school picker when `roles[]` has more than one entry (P6).

### 4.2 Family Dashboard

One card per child: name, grade, bell tier, status pill, route, their stop and
its scheduled time. Status pill carries **colour and words** — never colour
alone. Multi-child families are the norm; the list must not assume one.

### 4.3 Child Live

- Full-bleed map: bus marker + this child's stop.
- Bottom sheet: status pill · ETA to my stop / to school · **handover code**
  (afternoon only, large, tappable to enlarge) · attendant + driver row with
  masked call · today's timeline (collapsible) · `[Mark absent]` `[Call school]`.
- **Handover code is the afternoon centrepiece.** It is read aloud through a bus
  window in traffic noise — it must be the largest element on the screen.

### 4.4 Journey

90 days, per-day timeline, share a day. This is the killer feature (PART D):
every event with actor, coordinates and **server** time.

---

## 5. The live-map rule (enterprise L29) — safety-critical

```
terminalForMe = me_status in [absent, not_at_stop, alighted, returned_to_school]
showLiveMap   = has_active_trip && !terminalForMe && (isMorning || me_status != alighted)
```

- **Morning:** the map stays until the bus reaches school.
- **Afternoon:** the map closes for *this family* the moment their child is
  handed over — independent of the rest of the route.

⚠ **The server decides, the client obeys.** The existing implementation already
does the right thing and the native app must copy it exactly: when the map is
closed the bus coordinates are **omitted from the payload entirely**, not hidden
in the UI. A client-side check alone means the bus position is on the parent's
device and one bug away from being visible.

---

## 6. Notifications (PART F1)

All 15 event types. **Non-mutable** ones cannot be switched off by the parent —
safety and custody events are not preferences:

> #4 child boarded · #5 child not at stop · #6 arrived at school ·
> #8 child handed over · #9 nobody at stop · #10 returning to school ·
> #13 SOS · #15 trip cancelled

Requirements:

- One handler per `notification_type`. Tapping a push opens the child's live map.
- **PII rule (K9):** titles are generic-safe; bodies carry first name + stop name
  + time. **Never** the handover code, an address, or a phone number.
- **SMS fallback** for events 4, 6, 8, 9, 10, 13 only: if FCM reports
  non-delivery and the guardian has `sms_fallback=true`, send after 60 s.
- **Push is best-effort and the app must not depend on it.** Poll
  `/family-dashboard` every 10 s while foregrounded, and **fetch immediately in
  `onResume`** (F6, carried forward from enterprise L14 verbatim). A push that
  arrives while backgrounded is dropped with no buffering; scheduling the first
  poll 10 s out leaves the UI stale exactly when the parent opens it.

---

## 7. Offline behaviour — deliberately minimal

**Cache the journey history. Never cache live trip data.**

A parent looking at a cached "on the bus" from twenty minutes ago is worse off
than a parent seeing "no connection". This screen answers *"where is my child
right now"*, and a stale answer to that question is a safety problem, not a UX
one. Show an explicit offline state with the time of the last successful fetch.

---

## 8. Permissions

Request **only** these, each at the moment of first use with an explanation:

| Permission | Why | When |
|---|---|---|
| Notifications | The entire point of the app | After first successful login, not on launch |
| — | | |

**Not requested:** location (the parent's phone is never tracked — only the
bus), contacts, camera, storage, background location. Any of these on the store
listing invites a review rejection and a reasonable parent's suspicion.

---

## 9. Compliance and store review

This app concerns **the real-time location of identified minors**. Treat store
review and Indian data law as design inputs, not paperwork.

- **DPDP Act 2023 (India)** — processing children's data requires verifiable
  parental consent and prohibits tracking/behavioural advertising directed at
  children. No analytics SDK that profiles the child. No ad SDK at all.
- **Apple** — Guideline 5.1.4 (Kids) and 5.1.1 (data collection). A privacy
  policy URL and a complete privacy nutrition label are mandatory.
- **Google Play** — Families policy, Data Safety form, and a **Prominent
  Disclosure** for location data (the bus's location, shown to the parent).
- **Data retention** — journey records are 90 days in-app; PART K13 says
  anonymise, never destroy, so retention is a server concern, not a client one.
- **Account deletion** — both stores now require an in-app path to request
  deletion. ⚠ This collides with PART K13 and Invariant #4: the journey record
  and the audit log must survive. Resolve as *anonymise the guardian, retain the
  child's journey record* — and get this written down before submission.

---

## 10. Non-functional

- **Cold start to Family Dashboard: under 2 seconds** on a mid-range Android.
- **Accessibility:** minimum 44 pt tap targets (56 pt for anything
  safety-critical); full screen-reader labels; text scales to the OS setting
  without clipping; never colour alone to convey status.
- **Localisation:** English at pilot. Structure strings for Telugu and Hindi from
  day one — retrofitting is far more expensive than the initial discipline.
- **Sunlight legibility.** This app is opened one-handed at a kerb, often in
  direct sun, often while holding a child. High contrast, large type.
- **Crash reporting** that does not profile the child (Sentry with PII scrubbing).

---

## 11. Open decisions that block this app

Both are already on the project's open-decisions list, and this app is where
they stop being theoretical.

1. **Who owns the parent relationship — Zippi or the school?** Determines the
   support number in every notification template and the "Call school" button.
   **Decide before writing the first template.**
2. **Does every bus have an attendant?** The whole two-device split rests on it,
   and the parent app's copy ("the attendant marked Aarav boarded") assumes it.

---

## 12. Phasing

| Phase | Scope | Depends on |
|---|---|---|
| **0 — server** | `routes/api.php`, Sanctum, token on OTP verify, `role_links` (P6), `/family-dashboard`, `POST /device-token` | Nothing. Can start now. |
| **1 — app shell** | Login + OTP + role picker, Family Dashboard, Child Live without the map, Journey | Phase 0 |
| **2 — the reason for native** | FCM push (all 15 types), SMS fallback, Google Maps SDK, approach alerts | Phase 0, FCM + SMS credentials |
| **3 — store** | Privacy policy, Data Safety, nutrition label, account deletion, TestFlight / internal track | Phases 1–2 |

⚠ **Zippi Fleet is the harder dependency.** Everything this app *displays* —
boarded, arrived, handed over — is written by the attendant's app, which does
not exist. Until Layer 3 ships, the parent app can only show trips generated by
`school:generate-trips` and states set by hand. **A parent app shipped before
Fleet shows a schedule, not a journey.**

---

## 13. Credentials needed before Phase 2

None of these are in the repo, and each has a lead time:

- **Firebase project** + FCM server key; APNs auth key (Apple Developer Program,
  ₹8,200/yr) and a Google Play Console account (one-time $25).
- **SMS gateway** with **DLT-registered templates** — OTP, child boarded, child
  handed over, nobody at stop, returning to school, trip cancelled, SOS, absence
  confirmation. ⚠ **DLT approval takes days and will otherwise block launch.**
- **Google Maps API key** with the Directions and Maps SDK enabled — also
  unblocks `DriveTimeEstimator`, which the trip solver still substitutes with a
  conservative haversine fallback.

⚠ Re-read **PART P2** before wiring the SMS gateway. Both carried-forward bugs
are silent — the gateway returns success and the message never arrives:

1. An **empty DLT template id** is accepted and never delivered. Always pass a
   configured id with a hard-coded fallback.
2. Never write `'91' . ltrim($mobile, '91')`. `ltrim` treats `'91'` as a
   character **set** and strips leading 9s and 1s. Send the raw number.
