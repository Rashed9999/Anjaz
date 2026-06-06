class StringParser{

  static String maskedPhone (String phone){
    int maskedNumber = (phone.length * 0.7).floor();
    int remaining = phone.length - maskedNumber;

    int visiblePart = 0;

    if(remaining % 2 == 0){
      visiblePart = remaining ~/ 2;
    }else{
      visiblePart = (remaining / 2).ceil();
    }

    return "${phone.substring(0, visiblePart)}${'*' * (maskedNumber)}${phone.substring((maskedNumber + visiblePart), phone.length)}";
  }


}