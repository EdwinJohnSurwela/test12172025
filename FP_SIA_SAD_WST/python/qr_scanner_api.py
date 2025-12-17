"""
Library Hub - QR Code Scanner API
Supports OBS Virtual Camera and regular webcams
Works with Python 3.8+ including Python 3.14
"""

import os
import sys
import base64
import glob
import threading
import time
from io import BytesIO

# Check Python version
print(f"Python version: {sys.version}")

# Try to import Flask
try:
    from flask import Flask, request, jsonify
    from flask_cors import CORS
except ImportError:
    print("ERROR: Flask not installed. Run: pip install flask flask-cors")
    sys.exit(1)

# Try to import PIL
try:
    from PIL import Image
except ImportError:
    print("ERROR: Pillow not installed. Run: pip install Pillow")
    sys.exit(1)

# Try to import pyzbar (preferred for QR decoding)
PYZBAR_AVAILABLE = False
try:
    from pyzbar.pyzbar import decode as pyzbar_decode
    from pyzbar.pyzbar import ZBarSymbol
    PYZBAR_AVAILABLE = True
    print("pyzbar: Available (preferred QR decoder)")
except ImportError:
    print("WARNING: pyzbar not available. Run: pip install pyzbar")

# Try to import OpenCV
CV2_AVAILABLE = False
try:
    import cv2
    import numpy as np
    CV2_AVAILABLE = True
    print(f"OpenCV version: {cv2.__version__}")
except (ImportError, AttributeError) as e:
    print(f"WARNING: OpenCV not available - {e}")
    print("Camera features may be limited.")
    print("The scanner will use pyzbar for QR decoding if available.")

app = Flask(__name__)
CORS(app)

# Paths
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)
QR_CODES_FOLDER = os.path.join(PROJECT_DIR, 'qr_codes')

# Global camera variables
camera_capture = None
camera_thread = None
camera_running = False
last_frame = None
last_qr_result = None
frame_lock = threading.Lock()


def list_available_cameras():
    """List all available cameras including OBS Virtual Camera"""
    cameras = []
    if not CV2_AVAILABLE:
        return cameras
    
    # Check up to 10 camera indices
    for i in range(10):
        cap = cv2.VideoCapture(i, cv2.CAP_DSHOW)  # Use DirectShow on Windows
        if cap.isOpened():
            # Try to get camera name
            name = f"Camera {i}"
            
            # Read a test frame to verify it works
            ret, frame = cap.read()
            if ret:
                cameras.append({
                    'index': i,
                    'name': name,
                    'width': int(cap.get(cv2.CAP_PROP_FRAME_WIDTH)),
                    'height': int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
                })
            cap.release()
    
    return cameras


def start_camera(camera_index=0):
    """Start camera capture (works with OBS Virtual Camera)"""
    global camera_capture, camera_thread, camera_running, last_frame
    
    if not CV2_AVAILABLE:
        return False, "OpenCV not available"
    
    # Stop existing camera
    stop_camera()
    
    # Try to open camera with DirectShow (better for virtual cameras on Windows)
    camera_capture = cv2.VideoCapture(camera_index, cv2.CAP_DSHOW)
    
    if not camera_capture.isOpened():
        # Fallback to default backend
        camera_capture = cv2.VideoCapture(camera_index)
    
    if not camera_capture.isOpened():
        return False, f"Could not open camera {camera_index}"
    
    # Set camera properties for better quality
    camera_capture.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
    camera_capture.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)
    camera_capture.set(cv2.CAP_PROP_FPS, 30)
    
    camera_running = True
    
    # Start capture thread
    camera_thread = threading.Thread(target=camera_capture_loop, daemon=True)
    camera_thread.start()
    
    return True, "Camera started"


def camera_capture_loop():
    """Background thread to continuously capture frames"""
    global camera_capture, camera_running, last_frame, last_qr_result
    
    while camera_running and camera_capture and camera_capture.isOpened():
        ret, frame = camera_capture.read()
        if ret:
            with frame_lock:
                last_frame = frame.copy()
                
                # Try to decode QR from this frame
                result = decode_qr_from_cv2_frame(frame)
                if result:
                    last_qr_result = result
        
        time.sleep(0.033)  # ~30 FPS


def stop_camera():
    """Stop camera capture"""
    global camera_capture, camera_running, last_frame, last_qr_result
    
    camera_running = False
    
    if camera_capture:
        camera_capture.release()
        camera_capture = None
    
    last_frame = None
    last_qr_result = None


def get_current_frame():
    """Get the current camera frame as base64 JPEG"""
    global last_frame
    
    with frame_lock:
        if last_frame is None:
            return None
        
        # Encode as JPEG
        _, buffer = cv2.imencode('.jpg', last_frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
        return base64.b64encode(buffer).decode('utf-8')


def decode_qr(image):
    """Decode QR codes from image using OpenCV"""
    if not CV2_AVAILABLE:
        return []
        
    try:
        # Convert PIL Image to numpy array
        img_array = np.array(image)
        
        # Initialize QR code detector
        qr_detector = cv2.QRCodeDetector()
        
        # Detect and decode
        data, bbox, _ = qr_detector.detectAndDecode(img_array)
        
        if data:
            print(f"QR Code detected: {data}")
            return [{'data': data, 'type': 'QRCODE'}]
        else:
            return []
            
    except Exception as e:
        print(f"Error decoding QR: {str(e)}")
        return []


def decode_qr_from_cv2_frame(frame):
    """Decode QR code from OpenCV frame"""
    if not CV2_AVAILABLE or frame is None:
        return None
    
    # Try OpenCV QR detector
    try:
        detector = cv2.QRCodeDetector()
        data, bbox, _ = detector.detectAndDecode(frame)
        if data:
            return data.strip()
    except:
        pass
    
    return None


def preprocess_pil_image(pil_image):
    """Apply preprocessing to improve QR detection using PIL only"""
    results = [pil_image]
    
    # 1. Grayscale
    gray = pil_image.convert('L')
    results.append(gray.convert('RGB'))
    
    # 2. Increase contrast
    from PIL import ImageEnhance
    enhancer = ImageEnhance.Contrast(pil_image)
    results.append(enhancer.enhance(2.0))
    
    # 3. Increase sharpness
    enhancer = ImageEnhance.Sharpness(pil_image)
    results.append(enhancer.enhance(2.0))
    
    # 4. Threshold (convert to black and white)
    gray = pil_image.convert('L')
    threshold = gray.point(lambda x: 255 if x > 128 else 0, '1')
    results.append(threshold.convert('RGB'))
    
    # 5. Scale up if small
    width, height = pil_image.size
    if width < 300 or height < 300:
        scale = max(300 / width, 300 / height, 2)
        new_size = (int(width * scale), int(height * scale))
        upscaled = pil_image.resize(new_size, Image.Resampling.LANCZOS)
        results.append(upscaled)
    
    return results


def decode_qr_pyzbar(pil_image):
    """Decode QR using pyzbar (preferred method)"""
    if not PYZBAR_AVAILABLE:
        return None
        
    try:
        # pyzbar works directly with PIL images
        decoded_objects = pyzbar_decode(pil_image, symbols=[ZBarSymbol.QRCODE])
        
        if decoded_objects:
            # Return the first QR code found
            data = decoded_objects[0].data.decode('utf-8')
            return data.strip()
    except Exception as e:
        print(f"Error in pyzbar decode: {e}")
    
    return None


def decode_qr_cv2(pil_image):
    """Decode QR using OpenCV"""
    if not CV2_AVAILABLE:
        return None
        
    try:
        # Convert PIL to OpenCV format
        img_array = np.array(pil_image)
        # Convert RGB to BGR
        if len(img_array.shape) == 3:
            img_array = cv2.cvtColor(img_array, cv2.COLOR_RGB2BGR)
        
        detector = cv2.QRCodeDetector()
        data, bbox, _ = detector.detectAndDecode(img_array)
        
        if data:
            return data.strip()
    except Exception as e:
        print(f"Error in CV2 decode: {e}")
    
    return None


def decode_qr_all_methods(pil_image):
    """Try all available methods to decode QR code"""
    # Try pyzbar first (preferred, works without OpenCV)
    if PYZBAR_AVAILABLE:
        result = decode_qr_pyzbar(pil_image)
        if result:
            return result
    
    # Try OpenCV as fallback
    if CV2_AVAILABLE:
        result = decode_qr_cv2(pil_image)
        if result:
            return result
    
    # Try with preprocessing
    preprocessed = preprocess_pil_image(pil_image)
    
    for processed in preprocessed:
        # Try pyzbar first
        if PYZBAR_AVAILABLE:
            result = decode_qr_pyzbar(processed)
            if result:
                return result
        # Then OpenCV
        if CV2_AVAILABLE:
            result = decode_qr_cv2(processed)
            if result:
                return result
    
    return None


def load_reference_qr_codes():
    """Load reference QR codes from folder"""
    qr_codes = {}
    
    if not os.path.exists(QR_CODES_FOLDER):
        print(f"Note: QR codes folder not found at {QR_CODES_FOLDER}")
        return qr_codes
    
    extensions = ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.bmp']
    
    for ext in extensions:
        pattern = os.path.join(QR_CODES_FOLDER, ext)
        for filepath in glob.glob(pattern):
            try:
                img = Image.open(filepath)
                decoded = decode_qr_all_methods(img)
                if decoded:
                    filename = os.path.basename(filepath)
                    qr_codes[decoded] = {'filename': filename}
                    print(f"  Loaded: {decoded} <- {filename}")
            except Exception as e:
                print(f"  Error loading {filepath}: {e}")
    
    return qr_codes


# Load references at startup
print("\n" + "="*50)
print("Loading reference QR codes...")
print("="*50)
REFERENCE_QR_CODES = load_reference_qr_codes()
print(f"Loaded {len(REFERENCE_QR_CODES)} reference codes")
print("="*50 + "\n")


# ============ API ENDPOINTS ============

@app.route('/scan', methods=['POST'])
def scan_qr_code():
    """Scan QR code from base64 image"""
    try:
        # Check if any QR decoder is available
        if not PYZBAR_AVAILABLE and not CV2_AVAILABLE:
            return jsonify({
                'success': False, 
                'error': 'No QR decoder available. Install pyzbar: pip install pyzbar'
            })
        
        data = request.get_json()
        
        if not data or 'image' not in data:
            return jsonify({'success': False, 'error': 'No image provided'})
        
        # Get base64 data
        image_data = data['image']
        if ',' in image_data:
            image_data = image_data.split(',')[1]
        
        # Decode to PIL Image
        try:
            image_bytes = base64.b64decode(image_data)
            pil_image = Image.open(BytesIO(image_bytes))
            
            # Convert to RGB if needed
            if pil_image.mode != 'RGB':
                pil_image = pil_image.convert('RGB')
        except Exception as e:
            return jsonify({'success': False, 'error': f'Invalid image: {str(e)}'})
        
        # Decode QR
        decoded_text = decode_qr_all_methods(pil_image)
        
        if decoded_text:
            return jsonify({
                'success': True,
                'qr_code': decoded_text,
                'matched': decoded_text in REFERENCE_QR_CODES
            })
        else:
            return jsonify({'success': False, 'error': 'No QR code found'})
    
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/camera/list', methods=['GET'])
def list_cameras():
    """List all available cameras including OBS Virtual Camera"""
    cameras = list_available_cameras()
    return jsonify({
        'success': True,
        'cameras': cameras,
        'count': len(cameras),
        'opencv_available': CV2_AVAILABLE
    })


@app.route('/camera/start', methods=['POST'])
def api_start_camera():
    """Start camera capture"""
    data = request.get_json() or {}
    camera_index = data.get('camera_index', 0)
    
    success, message = start_camera(camera_index)
    return jsonify({
        'success': success,
        'message': message
    })


@app.route('/camera/stop', methods=['POST'])
def api_stop_camera():
    """Stop camera capture"""
    stop_camera()
    return jsonify({'success': True, 'message': 'Camera stopped'})


@app.route('/camera/frame', methods=['GET'])
def get_frame():
    """Get current camera frame as base64"""
    frame_data = get_current_frame()
    
    if frame_data:
        return jsonify({
            'success': True,
            'frame': f'data:image/jpeg;base64,{frame_data}'
        })
    else:
        return jsonify({
            'success': False,
            'error': 'No frame available'
        })


@app.route('/camera/scan', methods=['GET'])
def scan_from_camera():
    """Scan QR code from current camera frame"""
    global last_qr_result, last_frame
    
    # Check cached result first
    if last_qr_result:
        result = last_qr_result
        last_qr_result = None  # Clear after reading
        return jsonify({
            'success': True,
            'qr_code': result,
            'matched': result in REFERENCE_QR_CODES
        })
    
    # Try to scan current frame
    with frame_lock:
        if last_frame is not None:
            result = decode_qr_from_cv2_frame(last_frame)
            if result:
                return jsonify({
                    'success': True,
                    'qr_code': result,
                    'matched': result in REFERENCE_QR_CODES
                })
    
    return jsonify({'success': False, 'error': 'No QR code detected'})


@app.route('/health', methods=['GET'])
def health_check():
    """Health check"""
    return jsonify({
        'status': 'ok',
        'python_version': sys.version.split()[0],
        'opencv': CV2_AVAILABLE,
        'pyzbar': PYZBAR_AVAILABLE,
        'qr_decoder': 'pyzbar' if PYZBAR_AVAILABLE else ('opencv' if CV2_AVAILABLE else 'none'),
        'camera_running': camera_running,
        'reference_codes': len(REFERENCE_QR_CODES)
    })


@app.route('/codes', methods=['GET'])
def list_codes():
    """List reference codes"""
    return jsonify({
        'success': True,
        'codes': list(REFERENCE_QR_CODES.keys()),
        'count': len(REFERENCE_QR_CODES)
    })


@app.route('/', methods=['GET'])
def index():
    """API info"""
    return jsonify({
        'name': 'Library Hub QR Scanner',
        'status': 'running',
        'capabilities': {
            'opencv': CV2_AVAILABLE,
            'pyzbar': PYZBAR_AVAILABLE,
            'qr_decoder': 'pyzbar' if PYZBAR_AVAILABLE else ('opencv' if CV2_AVAILABLE else 'none'),
            'camera': CV2_AVAILABLE
        },
        'endpoints': {
            '/scan': 'POST - Scan QR from base64 image',
            '/camera/list': 'GET - List available cameras',
            '/camera/start': 'POST - Start camera capture',
            '/camera/stop': 'POST - Stop camera capture',
            '/camera/frame': 'GET - Get current frame',
            '/camera/scan': 'GET - Scan QR from camera'
        }
    })


def get_local_ip():
    """Get the local IP address of this machine"""
    try:
        import socket
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        local_ip = s.getsockname()[0]
        s.close()
        return local_ip
    except:
        return "127.0.0.1"


if __name__ == '__main__':
    print("="*50)
    print("  Library Hub QR Scanner API")
    print("="*50)
    print(f"  Local URL: http://localhost:5000")
    print(f"  Network URL: http://{get_local_ip()}:5000")
    print(f"  pyzbar: {'Available (QR decoder)' if PYZBAR_AVAILABLE else 'Not Available'}")
    print(f"  OpenCV: {'Available' if CV2_AVAILABLE else 'Not Available'}")
    
    if not PYZBAR_AVAILABLE and not CV2_AVAILABLE:
        print("\n  WARNING: No QR decoder available!")
        print("  Install pyzbar: pip install pyzbar")
    
    if CV2_AVAILABLE:
        print("\n  Available Cameras:")
        cameras = list_available_cameras()
        if cameras:
            for cam in cameras:
                print(f"    [{cam['index']}] {cam['name']} ({cam['width']}x{cam['height']})")
        else:
            print("    No cameras found")
        print("\n  TIP: Start OBS, enable Virtual Camera, then restart this server")
    else:
        print("\n  Camera features disabled - OpenCV not installed")
        print("  Install with: pip install opencv-python")
    
    print("="*50 + "\n")
    
    # Listen on all network interfaces (0.0.0.0) instead of localhost (127.0.0.1)
    # This allows other computers on the network to access the API
    app.run(host='0.0.0.0', port=5000, debug=False, threaded=True)
