# Zippi Parent — UI designs

Drop the design screens in this folder. Name each file after the screen it
replaces so there is no guesswork about which is which.

| Filename            | Screen it replaces        | Current Flutter file                |
|---------------------|---------------------------|-------------------------------------|
| `01-login.png`      | Phone number entry        | `lib/screens/login_screen.dart`     |
| `02-verify.png`     | OTP entry                 | `lib/screens/verify_screen.dart`    |
| `03-dashboard.png`  | Family dashboard (cards)  | `lib/screens/dashboard_screen.dart` |
| `04-child.png`      | Child Live                | `lib/screens/child_screen.dart`     |
| `05-journey.png`    | 90-day journey history    | `lib/screens/journey_screen.dart`   |

PNG or JPG both fine. Partial sets are fine too — send what you have.

If a screen has several states (loading, empty, error, bus-running vs
handed-over), add a suffix: `04-child-live.png`, `04-child-handed-over.png`.

## Anything else that helps

- `colors.txt` / `tokens.txt` — exact hex values, if you have them
- `fonts.txt` — font family names, if not a system font
- Any spacing or corner-radius values you want matched exactly

Without exact values I will read the colours off the images, which gets close
but not pixel-exact.
