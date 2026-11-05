import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';

void main() {
  group('AMIAL-PROGRESSIVE-KYC-LOGIN-002', () {
    test('tier-zero customer is never sent to account review prison', () {
      for (final state in [
        'active_unverified',
        'pending_review',
        'rejected',
        'verified',
      ]) {
        expect(
          UnifiedAuthController.shouldBlockHome(
            role: 'customer',
            verificationState: state,
          ),
          isFalse,
          reason:
              'KYC controls money/features for a customer; it must not block app entry.',
        );
      }
    });

    test('merchant and POS are fully blocked only when rejected', () {
      for (final role in ['merchant', 'pos']) {
        expect(
          UnifiedAuthController.shouldBlockHome(
            role: role,
            verificationState: 'pending_review',
          ),
          isFalse,
        );
        expect(
          UnifiedAuthController.shouldBlockHome(
            role: role,
            verificationState: 'verified',
          ),
          isFalse,
        );
        expect(
          UnifiedAuthController.shouldBlockHome(
            role: role,
            verificationState: 'rejected',
          ),
          isTrue,
        );
      }
    });

    test('admin is never subject to KYC home gate', () {
      expect(
        UnifiedAuthController.shouldBlockHome(
          role: 'admin',
          verificationState: 'pending_review',
        ),
        isFalse,
      );
    });

    test('agent keeps explicit approval gate', () {
      expect(
        UnifiedAuthController.shouldBlockHome(
          role: 'agent',
          verificationState: 'pending_review',
        ),
        isTrue,
      );
      expect(
        UnifiedAuthController.shouldBlockHome(
          role: 'agent',
          verificationState: 'verified',
        ),
        isFalse,
      );
    });
  });
}
