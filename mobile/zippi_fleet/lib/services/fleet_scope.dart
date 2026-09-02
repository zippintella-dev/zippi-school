import 'package:flutter/widgets.dart';

import 'fleet_store.dart';

/// Puts the one [FleetStore] in scope for the whole app.
///
/// An `InheritedNotifier` rather than a package: the Parent app carries no
/// state-management dependency either, and one notifier over a single mutable
/// trip does not need one. Every screen reads the same instance, so a child
/// marked boarded on the stop list is boarded on the drop list, the head count
/// and the completion gate at the same instant — there is no second copy to
/// disagree with the first.
class FleetScope extends InheritedNotifier<FleetStore> {
  const FleetScope({
    required FleetStore store,
    required super.child,
    super.key,
  }) : super(notifier: store);

  /// Reads the store AND subscribes this widget to it. The normal case.
  static FleetStore of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<FleetScope>();
    assert(scope != null, 'No FleetScope above this widget.');
    return scope!.notifier!;
  }

  /// Reads the store WITHOUT subscribing.
  ///
  /// ⚠ For starting a subscription or firing a one-off call from
  /// `didChangeDependencies`, where taking a dependency would rebuild the
  /// widget on every notify and restart whatever it just started.
  static FleetStore read(BuildContext context) {
    final scope = context.getInheritedWidgetOfExactType<FleetScope>();
    assert(scope != null, 'No FleetScope above this widget.');
    return scope!.notifier!;
  }
}
