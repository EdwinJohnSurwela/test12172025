"""
Library Hub QR Scanner - System Tray Application
Runs the QR Scanner API in the background with a system tray icon.
"""

import os
import sys
import threading
import subprocess
import time
import signal

# Check and install required packages
def install_packages():
    packages = ['pystray', 'Pillow', 'flask', 'flask-cors', 'opencv-python']
    import importlib
    for pkg in packages:
        pkg_import = pkg.replace('-', '_').split('[')[0]
        if pkg_import == 'opencv_python':
            pkg_import = 'cv2'
        try:
            importlib.import_module(pkg_import)
        except ImportError:
            print(f"Installing {pkg}...")
            subprocess.check_call([sys.executable, '-m', 'pip', 'install', pkg, '--quiet'])

# Install packages first
try:
    import pystray
    from PIL import Image, ImageDraw
except ImportError:
    install_packages()
    import pystray
    from PIL import Image, ImageDraw

# Global variables
server_thread = None
server_running = False
icon = None

def create_icon_image():
    """Create a simple QR code-like icon for the system tray."""
    size = 64
    image = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    
    # Background - DepEd Blue
    draw.rectangle([0, 0, size, size], fill='#1a4480')
    
    # QR code pattern (simplified)
    cell_size = 8
    margin = 8
    
    # Corner squares (like QR codes have)
    # Top-left
    draw.rectangle([margin, margin, margin + 16, margin + 16], outline='white', width=2)
    draw.rectangle([margin + 4, margin + 4, margin + 12, margin + 12], fill='white')
    
    # Top-right  
    draw.rectangle([size - margin - 16, margin, size - margin, margin + 16], outline='white', width=2)
    draw.rectangle([size - margin - 12, margin + 4, size - margin - 4, margin + 12], fill='white')
    
    # Bottom-left
    draw.rectangle([margin, size - margin - 16, margin + 16, size - margin], outline='white', width=2)
    draw.rectangle([margin + 4, size - margin - 12, margin + 12, size - margin - 4], fill='white')
    
    # Some data cells in middle
    for i in range(3):
        for j in range(3):
            if (i + j) % 2 == 0:
                x = 24 + i * 6
                y = 24 + j * 6
                draw.rectangle([x, y, x + 4, y + 4], fill='white')
    
    return image

def get_server_status():
    """Check if the Flask server is running."""
    import socket
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(1)
        result = sock.connect_ex(('127.0.0.1', 5000))
        sock.close()
        return result == 0
    except:
        return False

def run_flask_server():
    """Run the Flask QR Scanner API server."""
    global server_running
    
    # Change to the python directory
    script_dir = os.path.dirname(os.path.abspath(__file__))
    os.chdir(script_dir)
    
    # Import and run the server
    try:
        server_running = True
        
        # Import Flask app from qr_scanner_api
        sys.path.insert(0, script_dir)
        
        # Check if qr_scanner_api.py exists
        api_file = os.path.join(script_dir, 'qr_scanner_api.py')
        if not os.path.exists(api_file):
            print(f"Error: {api_file} not found!")
            server_running = False
            return
        
        # Run the Flask app
        from qr_scanner_api import app
        
        # Disable Flask's reloader and debugger for production
        import logging
        log = logging.getLogger('werkzeug')
        log.setLevel(logging.ERROR)
        
        app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False, threaded=True)
        
    except Exception as e:
        print(f"Server error: {e}")
        server_running = False

def start_server(icon_ref=None, item=None):
    """Start the Flask server in a background thread."""
    global server_thread, server_running
    
    if server_running or get_server_status():
        notify("Scanner Already Running", "The QR Scanner is already active.")
        return
    
    server_thread = threading.Thread(target=run_flask_server, daemon=True)
    server_thread.start()
    
    # Wait a moment for server to start
    time.sleep(2)
    
    if get_server_status():
        notify("Scanner Started", "QR Scanner API is now running on port 5000.")
    else:
        notify("Scanner Error", "Failed to start the scanner. Check the logs.")

def stop_server(icon_ref=None, item=None):
    """Stop the Flask server."""
    global server_running
    
    if not server_running and not get_server_status():
        notify("Scanner Not Running", "The QR Scanner is not currently active.")
        return
    
    server_running = False
    
    # Kill the Flask process on port 5000
    try:
        if sys.platform == 'win32':
            # Find and kill process on port 5000
            result = subprocess.run(['netstat', '-ano'], capture_output=True, text=True)
            for line in result.stdout.split('\n'):
                if ':5000' in line and 'LISTENING' in line:
                    parts = line.split()
                    if len(parts) >= 5:
                        pid = parts[-1]
                        subprocess.run(['taskkill', '/F', '/PID', pid], capture_output=True)
                        break
        notify("Scanner Stopped", "QR Scanner API has been stopped.")
    except Exception as e:
        print(f"Error stopping server: {e}")

def show_status(icon_ref=None, item=None):
    """Show the current server status."""
    if get_server_status():
        notify("Scanner Status", "✅ QR Scanner is RUNNING on http://localhost:5000")
    else:
        notify("Scanner Status", "❌ QR Scanner is NOT running")

def open_browser(icon_ref=None, item=None):
    """Open the Library Hub in the default browser."""
    import webbrowser
    webbrowser.open('http://localhost/FP_SIA_SAD_WST/php/index.php')

def notify(title, message):
    """Show a notification."""
    global icon
    if icon:
        try:
            icon.notify(message, title)
        except:
            print(f"{title}: {message}")

def quit_app(icon_ref=None, item=None):
    """Quit the application."""
    global icon, server_running
    
    # Stop server first
    stop_server()
    server_running = False
    
    # Stop the icon
    if icon:
        icon.stop()
    
    sys.exit(0)

def setup_menu():
    """Create the system tray menu."""
    return pystray.Menu(
        pystray.MenuItem("📊 Status", show_status),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("▶️ Start Scanner", start_server),
        pystray.MenuItem("⏹️ Stop Scanner", stop_server),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("🌐 Open Library Hub", open_browser),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("❌ Exit", quit_app)
    )

def main():
    """Main entry point."""
    global icon, server_running
    
    # Check if already running on port 5000
    if get_server_status():
        print("Scanner is already running on port 5000")
        # Still create the tray icon
    else:
        # Auto-start the server
        server_thread = threading.Thread(target=run_flask_server, daemon=True)
        server_thread.start()
        time.sleep(1)
    
    # Create the system tray icon
    icon_image = create_icon_image()
    icon = pystray.Icon(
        "Library Hub Scanner",
        icon_image,
        "Library Hub QR Scanner",
        setup_menu()
    )
    
    # Show startup notification after a short delay
    def startup_notify():
        time.sleep(2)
        if get_server_status():
            notify("Library Hub Scanner", "QR Scanner is running in the system tray.\nRight-click the icon for options.")
    
    threading.Thread(target=startup_notify, daemon=True).start()
    
    # Run the icon (this blocks)
    icon.run()

if __name__ == "__main__":
    main()
