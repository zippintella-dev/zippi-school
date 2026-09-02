import 'dart:async';

import 'package:flutter/material.dart';

import '../config.dart';
import '../models/family_card.dart';
import '../services/api_client.dart';
import '../services/auth_store.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'activity_screen.dart';
import 'home_screen.dart';
import 'select_child_screen.dart';
import 'you_screen.dart';

/// The app shell: Home · Activity · You, scoped to ONE selected child.
///
/// A guardian with several children picks one after signing in and the whole
/// app follows that choice. One poll feeds all three tabs — polling per-tab
/// would triple the request rate for the same data and let the tabs disagree
/// about where a child is.
///
/// ⚠ The selection narrows what is DISPLAYED. It is not what keeps one family's
/// children away from another's — that is the server's guardian→children link,
/// which every request resolves through. Do not push this id to the API as if
/// it granted anything.
class HomeShell extends StatefulWidget {
  final ApiClient api;
  final AuthStore auth;
  final VoidCallback onSignedOut;

  const HomeShell({
    required this.api,
    required this.auth,
    required this.onSignedOut,
    super.key,
  });

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with WidgetsBindingObserver {
  int _tab = 0;

  List<FamilyCard>? _cards;
  int? _selectedId;
  bool _switching = false;

  String? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _poll = Timer.periodic(Config.pollInterval, (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// ⚠ PART F6 / enterprise L14, verbatim: fetch IMMEDIATELY on resume. A push
  /// that arrives while backgrounded is dropped with no buffering, so waiting
  /// for the next tick leaves the screen stale at exactly the moment the parent
  /// opens it.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load(silent: true);
  }

  Future<void> _load({bool silent = false}) async {
    try {
      final cards = await widget.api.familyDashboard();
      if (!mounted) return;

      final stored = await widget.auth.selectedChildId();

      setState(() {
        _cards = cards;
        _error = null;

        // Keep the current choice if it is still one of this guardian's
        // children — a child can be retired, or the roster can change.
        final valid = {for (final c in cards) c.childId};

        _selectedId ??= (stored != null && valid.contains(stored)) ? stored : null;
        if (_selectedId != null && !valid.contains(_selectedId)) {
          _selectedId = null;
        }

        // One child is not a choice. Don't make them tap through a picker.
        if (_selectedId == null && cards.length == 1) {
          _selectedId = cards.first.childId;
          widget.auth.selectChild(_selectedId!);
        }
      });
    } on ApiException catch (e) {
      if (!mounted) return;

      if (e.isUnauthenticated) {
        widget.onSignedOut();
        return;
      }

      // A failed background poll must not blow away a good screen.
      if (!silent || _cards == null) setState(() => _error = e.message);
    }
  }

  Future<void> _select(FamilyCard c) async {
    await widget.auth.selectChild(c.childId);

    if (!mounted) return;

    setState(() {
      _selectedId = c.childId;
      _switching = false;
      _tab = 0;
    });
  }

  Future<void> _signOut() async {
    await widget.api.logout();
    widget.onSignedOut();
  }

  @override
  Widget build(BuildContext context) {
    final cards = _cards;

    if (cards == null) {
      return Scaffold(
        body: SafeArea(
          child: _error != null
              ? ErrorState(_error!, onRetry: _load)
              : const Center(child: CircularProgressIndicator(color: Z.turquoise)),
        ),
      );
    }

    if (cards.isEmpty) {
      return Scaffold(
        body: SafeArea(
          child: Column(
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 20, 20, 0),
                child: Align(
                    alignment: Alignment.centerLeft, child: ZippiWordmark()),
              ),
              const Expanded(
                child: EmptyState(
                  'No students linked yet',
                  "Your school hasn't linked any students to this number.\n"
                  'Please contact the school office.',
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton(
                  onPressed: _signOut,
                  child: const Text('Log out'),
                ),
              ),
            ],
          ),
        ),
      );
    }

    // No choice made yet, or the parent asked to switch.
    if (_selectedId == null || _switching) {
      return SelectChildScreen(
        cards: cards,
        currentChildId: _selectedId,
        onSelect: _select,
        onCancel: _switching ? () => setState(() => _switching = false) : null,
      );
    }

    final selected = cards.firstWhere(
      (c) => c.childId == _selectedId,
      orElse: () => cards.first,
    );

    return Scaffold(
      body: SafeArea(bottom: false, child: _body(selected, cards)),
      bottomNavigationBar:
          ZBottomNav(index: _tab, onTap: (i) => setState(() => _tab = i)),
    );
  }

  Widget _body(FamilyCard selected, List<FamilyCard> cards) {
    // ⚠ Every tab receives ONLY the selected child. Passing the full list and
    // filtering inside each screen is how a sibling's status leaks back onto a
    // screen that is supposed to be focused on one child.
    return switch (_tab) {
      1 => ActivityScreen(child: selected, onRefresh: _load),
      2 => YouScreen(
          api: widget.api,
          child: selected,
          siblingCount: cards.length,
          onSwitchChild: () => setState(() => _switching = true),
          onSignOut: _signOut,
        ),
      _ => HomeScreen(
          api: widget.api,
          child: selected,
          siblingCount: cards.length,
          onRefresh: _load,
          onSwitchChild: () => setState(() => _switching = true),
        ),
    };
  }
}
