import 'package:camera/camera.dart';
import 'package:dotted_border/dotted_border.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amyal_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amyal_pay/features/camera_verification/widgets/camera_instruction_widget.dart';
import 'package:amyal_pay/features/setting/screens/edit_profile_screen.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'dart:ui' as ui;



class CameraScreen extends StatefulWidget {
  final bool fromEditProfile;
  final bool isBarCodeScan;
  final bool isHome;
  final String? transactionType;
  final bool fromSearchContact;
  const CameraScreen({
    super.key,
    required this.fromEditProfile,
    this.isBarCodeScan = false,
    this.isHome = false,
    this.transactionType = '',
    this.fromSearchContact = false,
  });

  @override
  State<CameraScreen> createState() => _CameraScreenState();
}

class _CameraScreenState extends State<CameraScreen> {


  @override
  void dispose() {
    Get.find<CameraScreenController>().stopLiveFeed();
    super.dispose();
  }
  @override
  void initState() {
    Get.find<CameraScreenController>().valueInitialize(widget.fromEditProfile);
    Get.find<CameraScreenController>().startLiveFeed(
      isQrCodeScan: widget.isBarCodeScan,
      isHome: widget.isHome,
      transactionType: widget.transactionType,
      fromSearchContact: widget.fromSearchContact,
    );

    super.initState();
  }


  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final double overlaySide = size.width * 0.85; // increased from 0.70


    return Scaffold(
      appBar: AppBar(
        title: Text(widget.isBarCodeScan ? 'scanner'.tr : 'face_verification'.tr),
        automaticallyImplyLeading:
            (Get.previousRoute.split('?').first) == RouteHelper.verifyScreen ? false : true,
        actions: [
          if (!widget.isBarCodeScan && !widget.fromEditProfile)
            TextButton(
              onPressed: () {
                if (widget.fromEditProfile) {
                  Get.off(() => const EditProfileScreen());
                } else {
                  Get.off(() => const AmialRegistrationWizardScreen());
                }
              },
              child: Text('skip'.tr, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
            ),
        ],
      ),
      body: Column(children: [
        Flexible(flex: 2, child: GetBuilder<CameraScreenController>(
          builder: (cameraController) {
            if (cameraController.controller == null ||
                cameraController.controller?.value.isInitialized == false) {
              return Container(
                color: Colors.black,
                child: const Center(
                  child: CircularProgressIndicator(color: Colors.white),
                ),
              );
            }

            final controller = cameraController.controller!;

            return Center(child: Stack(fit: StackFit.expand, children: [
              ClipRect(child: OverflowBox(
                alignment: Alignment.center,
                child: FittedBox(
                  fit: BoxFit.cover,
                  child: SizedBox(
                    width: size.width,
                    height: size.width * controller.value.aspectRatio,
                    child: CameraPreview(controller),
                  ),
                ),
              )),

              // Blur and dim everything outside the centered square
              Positioned.fill(child: Center(
                child: Stack(children: [
                  ClipPath(
                    clipper: _InvertedRectClipper(
                      squareSize: overlaySide,
                    ),
                    child: BackdropFilter(
                      filter: ui.ImageFilter.blur(sigmaX: 2, sigmaY: 2),
                      child: Container(color: Colors.black.withValues(alpha: 0.3)),
                    ),
                  ),
                ]),
              )),

              Center(child: DottedBorder(
                options: RectDottedBorderOptions(
                  strokeWidth: 3,
                  dashPattern: const [10],
                  color: Colors.white,
                ),
                child: SizedBox(
                  width: overlaySide,
                  height: overlaySide,
                ),
              )),
            ]));
          },
        )),

        Flexible(flex: 1, child: CameraInstructionWidget(
          isBarCodeScan: widget.isBarCodeScan,
        )),
      ]),
    );
  }

}


class _InvertedRectClipper extends CustomClipper<Path> {
  final double squareSize;
  _InvertedRectClipper({required this.squareSize});

  @override
  Path getClip(Size size) {
    final Path full = Path()..addRect(Rect.fromLTWH(0, 0, size.width, size.height));
    final double left = (size.width - squareSize) / 2;
    final double top = (size.height - squareSize) / 2;
    final Rect hole = Rect.fromLTWH(left, top, squareSize, squareSize);
    final Path cutout = Path()..addRect(hole);
    return Path.combine(PathOperation.difference, full, cutout);
  }

  @override
  bool shouldReclip(covariant _InvertedRectClipper oldClipper) {
    return oldClipper.squareSize != squareSize;
  }
}
