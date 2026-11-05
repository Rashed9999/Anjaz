import 'package:amial_pay/helper/app_resume_state.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<AppResumeState> createState() async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    final preferences = await SharedPreferences.getInstance();
    return AppResumeState(sharedPreferences: preferences);
  }

  test('restores the last selected navigation tab during the short window',
      () async {
    final state = await createState();

    state.rememberNavbar('profile');
    state.didChangeAppLifecycleState(AppLifecycleState.paused);

    expect(
      state.takeEligibleDestination(),
      RouteHelper.getNavBarRoute(selectedPage: 'profile'),
    );
  });

  test('does not persist a financial flow as a resumable destination', () async {
    final state = await createState();

    state.didPush(
      PageRouteBuilder<void>(
        pageBuilder: (_, __, ___) => const SizedBox.shrink(),
        settings: const RouteSettings(name: RouteHelper.sendMoney),
      ),
      null,
    );
    state.didChangeAppLifecycleState(AppLifecycleState.paused);

    expect(state.takeEligibleDestination(), isNull);
  });
}
