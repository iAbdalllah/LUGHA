import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_mobile/psn.dart';

void main() {
  test('PSN user id becomes 8 little-endian bytes, base64', () {
    // 0x0102030405060708 = 72623859790382856
    expect(base64Decode(Psn.accountIdFromUserId('72623859790382856')), [8, 7, 6, 5, 4, 3, 2, 1]);
    // Ids above 2^63 (unsigned) still fit in 8 bytes.
    expect(base64Decode(Psn.accountIdFromUserId('18446744073709551615')), List.filled(8, 255));
  });

  test('sign-in page asks Sony for a code redirected to Remote Play', () {
    final u = Psn.authorizeUrl();
    expect(u.host, 'auth.api.sonyentertainmentnetwork.com');
    expect(u.queryParameters['redirect_uri'], Psn.redirect);
    expect(u.queryParameters['response_type'], 'code');
  });
}
