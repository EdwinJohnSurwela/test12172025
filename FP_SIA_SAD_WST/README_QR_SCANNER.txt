================================================================================
                    LIBRARY HUB - QR SCANNER SETUP GUIDE
================================================================================

This scanner supports:
- Browser webcam (default)
- OBS Virtual Camera (for testing with videos/images)
- Any virtual camera software

================================================================================
                         OBS VIRTUAL CAMERA SETUP
================================================================================

1. INSTALL OBS STUDIO
   Download from: https://obsproject.com/
   
2. SET UP OBS FOR QR CODE TESTING
   a. Open OBS Studio
   b. Add a new Source:
      - Click "+" under Sources
      - Choose "Image" for static QR code OR
      - Choose "Media Source" for video with QR codes OR
      - Choose "Window Capture" to capture QR code from screen
   
   c. Position the QR code to fill the preview
   
3. START VIRTUAL CAMERA
   - In OBS, click "Start Virtual Camera" button (bottom right)
   - OBS will now broadcast as a virtual webcam
   
4. USE IN LIBRARY HUB
   - The Python scanner will detect "OBS Virtual Camera"
   - Select it from the camera dropdown in the web interface

================================================================================
                              QUICK START
================================================================================

1. First time setup:
   - Double-click: python\install_dependencies.bat

2. Start the scanner:
   - Double-click: python\start_scanner.bat
   - Keep this window open

3. (Optional) For OBS Virtual Camera:
   - Open OBS Studio
   - Add your QR code source
   - Click "Start Virtual Camera"

4. Open browser:
   http://localhost/FP_SIA_SAD_WST/php/index.php

================================================================================
                           TROUBLESHOOTING
================================================================================

OBS Virtual Camera not showing:
- Make sure OBS is running BEFORE starting the Python scanner
- Click "Start Virtual Camera" in OBS
- Restart the Python scanner

Camera not working:
- Check browser permissions
- Try a different browser (Chrome recommended)
- Make sure no other app is using the camera

Python errors:
- Run install_dependencies.bat again
- For pyzbar: Install zbar from
  https://sourceforge.net/projects/zbar/files/zbar/0.10/

================================================================================
                              API ENDPOINTS
================================================================================

The Python API provides these endpoints:

GET  /health         - Check if server is running
GET  /codes          - List loaded QR codes
POST /scan           - Scan QR from base64 image
GET  /camera/list    - List available cameras
POST /camera/start   - Start camera capture
POST /camera/stop    - Stop camera capture
GET  /camera/frame   - Get current camera frame
GET  /camera/scan    - Scan QR from camera feed

================================================================================