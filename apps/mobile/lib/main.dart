import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'l10n.dart';
import 'screens/account_screen.dart';
import 'screens/devices_screen.dart';
import 'screens/library_screen.dart';
import 'screens/sign_in_screen.dart';
import 'screens/tv_screen.dart';
import 'state.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(LughaApp(app: AppState()..init()));
}

const amber = Color(0xFFE9B44C);

class LughaApp extends StatelessWidget {
  const LughaApp({super.key, required this.app});
  final AppState app;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: app,
      builder: (context, _) => MaterialApp(
        title: 'LUGHA',
        debugShowCheckedModeBanner: false,
        locale: Locale(L.lang),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        theme: ThemeData(
          brightness: Brightness.dark,
          colorScheme: ColorScheme.fromSeed(seedColor: amber, brightness: Brightness.dark, surface: const Color(0xFF16181D)),
          scaffoldBackgroundColor: const Color(0xFF0E0F12),
          fontFamily: null,
        ),
        home: app.signedIn ? Shell(app: app) : SignInScreen(app: app),
      ),
    );
  }
}

class Shell extends StatefulWidget {
  const Shell({super.key, required this.app});
  final AppState app;
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    widget.app.addListener(_onApp);
  }

  @override
  void dispose() {
    widget.app.removeListener(_onApp);
    super.dispose();
  }

  void _onApp() {
    if (widget.app.deviceLimitReached && _tab != 1) setState(() => _tab = 1); // show Devices so a slot can be freed
  }

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    final pages = [LibraryScreen(app: app), DevicesScreen(app: app), TvScreen(app: app), AccountScreen(app: app)];
    return Scaffold(
      body: SafeArea(child: pages[_tab]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.library_books_outlined), label: L.t('Library')),
          NavigationDestination(icon: const Icon(Icons.devices_outlined), label: L.t('Devices')),
          NavigationDestination(icon: const Icon(Icons.tv_outlined), label: L.t('TV')),
          NavigationDestination(icon: const Icon(Icons.person_outline), label: L.t('Account')),
        ],
      ),
    );
  }
}
