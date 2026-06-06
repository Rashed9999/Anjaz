import 'package:flutter/material.dart';

class GlobalKeyManagerModel {
  final GlobalKey appbarBalanceKey;
  final GlobalKey sendMoneyKey;
  final GlobalKey cashOutKey;
  final GlobalKey addMoneyKey;
  final GlobalKey requestMoneyKey;
  final GlobalKey sendMoneyRequestKey;
  final GlobalKey withdrawKey;
  final GlobalKey scrollableKey;

  // List to store all the keys
  final List<GlobalKey> visibleKeys = [];

  // Constructor to initialize all the keys
  GlobalKeyManagerModel()
      : appbarBalanceKey = GlobalKey(),
        sendMoneyKey = GlobalKey(),
        cashOutKey = GlobalKey(),
        addMoneyKey = GlobalKey(),
        requestMoneyKey = GlobalKey(),
        sendMoneyRequestKey = GlobalKey(),
        withdrawKey = GlobalKey(),
        scrollableKey = GlobalKey() {
    // Initialize the list with all the keys
    visibleKeys.addAll([
      appbarBalanceKey,
      sendMoneyKey,
      cashOutKey,
      addMoneyKey,
      requestMoneyKey,
      sendMoneyRequestKey,
      withdrawKey,
      scrollableKey,
    ]);
  }

  // Method to get the list of all keys
  List<GlobalKey> getAllKeys() {
    return visibleKeys;
  }
}