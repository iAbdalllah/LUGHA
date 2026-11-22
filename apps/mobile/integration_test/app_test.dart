// Drives the real app on a device/emulator against a running backend (http://10.0.2.2:8040 from the Android
// emulator, http://127.0.0.1:8040 from the iOS simulator): sign in → sync installs the package through the Rust
// engine → "Try a line" returns the Arabic translation → report that line to the reviewers.
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:lugha_mobile/main.dart';
import 'package:lugha_mobile/state.dart';

Future<void> pumpUntil(WidgetTester t, Finder f, {Duration timeout = const Duration(seconds: 40), String Function()? context}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await t.pump(const Duration(milliseconds: 250));
    if (f.evaluate().isNotEmpty) return;
  }
  throw TestFailure('timed out waiting for $f${context != null ? ' — app status: ${context()}' : ''}');
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('sign in, sync, translate and report a line', (t) async {
    await const FlutterSecureStorage().deleteAll(); // fresh install state
    final app = AppState();
    await app.init();
    addTearDown(() async {
      if (app.deviceId != null) await app.api.removeDevice(app.deviceId!); // leave the account as we found it
    });
    await t.pumpWidget(LughaApp(app: app));
    await t.pumpAndSettle();

    await t.enterText(find.byKey(const Key('email')), 'player@lugha.test');
    await t.enterText(find.byKey(const Key('password')), 'password');
    await t.tap(find.byKey(const Key('signin')));

    // Library appears once sync finished installing the package.
    await pumpUntil(t, find.textContaining('v1.0.0'), context: () => app.status);
    expect(app.loaded.map((p) => p.packageId), contains('sandline-sa-full'));
    expect(app.deviceId, isNotNull);

    await t.enterText(find.byKey(const Key('try')), 'Water costs more than gold out here');
    await t.testTextInput.receiveAction(TextInputAction.done);
    await t.pumpAndSettle();
    expect(find.text('الماء هنا أغلى من الذهب.'), findsOneWidget);

    // Report a line by the id the engine matched. A rerun finds its earlier report still open; the server says so.
    await t.ensureVisible(find.byKey(const Key('try')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('try'))); // the first lookup's "done" closed the keyboard; focus again
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('try')), 'Continue');
    await t.pump();
    await t.tap(find.byIcon(Icons.translate));
    await t.pumpAndSettle();
    expect(app.history.first.source, 'Continue');
    expect(app.history.first.reportable, isTrue);
    await t.tap(find.byKey(const Key('report')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('report-reason')));
    await t.pumpAndSettle();
    await t.tap(find.text('خطأ إملائي').last);
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('report-note')), 'integration test');
    await t.tap(find.byKey(const Key('report-send')));
    await pumpUntil(t, find.byWidgetPredicate((w) => w is Text && (w.data == 'شكرًا، وصل بلاغك لفريق المراجعة.' || w.data == 'لديك بلاغ مفتوح لهذا السطر بالفعل.')));
  });
}
