import UIKit
import Flutter
import Firebase
import AVFoundation
import AudioToolbox
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate {
  private let synthesizer = AVSpeechSynthesizer()

  private func resolveArabicVoice() -> AVSpeechSynthesisVoice? {
    return AVSpeechSynthesisVoice(language: "ar-SA")
      ?? AVSpeechSynthesisVoice(language: "ar")
  }

  private func speakArabic(_ text: String) {
    let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !trimmed.isEmpty, let voice = resolveArabicVoice() else { return }

    do {
      try AVAudioSession.sharedInstance().setCategory(
        .playback,
        mode: .spokenAudio,
        options: [.duckOthers, .mixWithOthers]
      )
      try AVAudioSession.sharedInstance().setActive(true)
    } catch {
      // الصوت تحسين لا يسقط المسار المالي
    }

    if synthesizer.isSpeaking {
      synthesizer.stopSpeaking(at: .immediate)
    }
    let utterance = AVSpeechUtterance(string: trimmed)
    utterance.voice = voice
    utterance.rate = AVSpeechUtteranceDefaultSpeechRate
    synthesizer.speak(utterance)
  }

  private func playChimeThenSpeak(soundId: SystemSoundID, speech: String?) {
    AudioServicesPlaySystemSoundWithCompletion(soundId) { [weak self] in
      guard let text = speech, !text.isEmpty else { return }
      DispatchQueue.main.async {
        self?.speakArabic(text)
      }
    }
  }

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    FirebaseApp.configure()
    UNUserNotificationCenter.current().delegate = self

    if let controller = window?.rootViewController as? FlutterViewController {
      let soundChannel = FlutterMethodChannel(
        name: "amial_pay/feedback_sound",
        binaryMessenger: controller.binaryMessenger
      )
      soundChannel.setMethodCallHandler { [weak self] call, result in
        let args = call.arguments as? [String: Any]
        let customSpeech = (args?["speech"] as? String)?
          .trimmingCharacters(in: .whitespacesAndNewlines)

        switch call.method {
        case "success":
          let phrase = (customSpeech?.isEmpty == false) ? customSpeech! : "تم الدفع بنجاح"
          self?.playChimeThenSpeak(soundId: 1057, speech: phrase)
          result(true)
        case "failure":
          let phrase = (customSpeech?.isEmpty == false) ? customSpeech! : "فشل الدفع"
          self?.playChimeThenSpeak(soundId: 1053, speech: phrase)
          result(true)
        case "notify":
          self?.playChimeThenSpeak(soundId: 1007, speech: customSpeech)
          result(true)
        case "speak":
          if let phrase = customSpeech, !phrase.isEmpty {
            self?.speakArabic(phrase)
          }
          result(true)
        case "canSpeakArabic":
          result(self?.resolveArabicVoice() != nil)
        default:
          result(FlutterMethodNotImplemented)
        }
      }
    }

    GeneratedPluginRegistrant.register(with: self)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
