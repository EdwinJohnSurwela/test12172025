"""
OBS ESP32-CAM Auto Setup Script
================================
Automatically configures OBS Studio for ESP32-CAM streaming:
- Creates/switches to "QR Scanner" scene collection
- Sets up browser source for ESP32-CAM stream
- Monitors stream and auto-refreshes if frozen
- Starts virtual camera

Requirements:
  pip install obsws-python requests

Usage:
  python obs_esp32cam_setup.py [--esp32-ip 192.168.1.100] [--obs-port 4455] [--obs-password yourpassword]
"""

import time
import sys
import argparse
import threading
import hashlib
from datetime import datetime

# Check dependencies
try:
    import obsws_python as obs
except ImportError:
    print("❌ obsws-python not installed. Run: pip install obsws-python")
    sys.exit(1)

try:
    import requests
except ImportError:
    print("❌ requests not installed. Run: pip install requests")
    sys.exit(1)


# =============================================================================
# CONFIGURATION
# =============================================================================
DEFAULT_ESP32_IP = "192.168.1.100"
DEFAULT_OBS_HOST = "localhost"
DEFAULT_OBS_PORT = 4455
DEFAULT_OBS_PASSWORD = ""  # Leave empty if no password set in OBS

SCENE_COLLECTION_NAME = "QR Scanner"
SCENE_NAME = "ESP32-CAM Scene"
SOURCE_NAME = "ESP32-CAM Browser"

# Stream monitoring settings
FREEZE_CHECK_INTERVAL = 5  # seconds between freeze checks
FREEZE_THRESHOLD = 3       # number of identical frames to consider frozen
FRAME_HASH_SAMPLES = 5     # frames to sample for hash comparison


# =============================================================================
# OBS CONTROLLER CLASS
# =============================================================================
class OBSController:
    def __init__(self, host, port, password, esp32_ip):
        self.host = host
        self.port = port
        self.password = password
        self.esp32_ip = esp32_ip
        self.stream_url = f"http://{esp32_ip}:81/stream"
        self.browser_url = f"http://{esp32_ip}/"
        self.client = None
        self.monitoring = False
        self.last_frame_hash = None
        self.frozen_count = 0
        
    def connect(self, max_retries=10, retry_delay=2):
        """Connect to OBS WebSocket server with retry logic"""
        print(f"🔌 Connecting to OBS at {self.host}:{self.port}...")
        
        for attempt in range(max_retries):
            try:
                if self.password:
                    self.client = obs.ReqClient(host=self.host, port=self.port, password=self.password, timeout=5)
                else:
                    self.client = obs.ReqClient(host=self.host, port=self.port, timeout=5)
                print("✅ Connected to OBS!")
                return True
            except Exception as e:
                if attempt < max_retries - 1:
                    print(f"   Attempt {attempt + 1}/{max_retries} failed, retrying in {retry_delay}s...")
                    time.sleep(retry_delay)
                else:
                    print(f"❌ Failed to connect to OBS after {max_retries} attempts: {e}")
                    print("\n⚠️  Make sure:")
                    print("   1. OBS Studio is running")
                    print("   2. WebSocket server is enabled (Tools → WebSocket Server Settings)")
                    print("   3. Port and password match your OBS settings")
                    return False
        return False
    
    def disconnect(self):
        """Disconnect from OBS"""
        if self.client:
            try:
                self.client = None
                print("🔌 Disconnected from OBS")
            except:
                pass
    
    def setup_scene_collection(self):
        """Create or switch to QR Scanner scene collection"""
        print(f"📁 Setting up scene collection: {SCENE_COLLECTION_NAME}")
        
        try:
            # Get list of scene collections
            collections = self.client.get_scene_collection_list()
            existing_collections = collections.scene_collections
            
            if SCENE_COLLECTION_NAME in existing_collections:
                print(f"   Found existing collection, switching to it...")
                self.client.set_current_scene_collection(SCENE_COLLECTION_NAME)
            else:
                print(f"   Creating new scene collection...")
                # Create new scene collection (this also switches to it)
                self.client.create_scene_collection(SCENE_COLLECTION_NAME)
            
            time.sleep(1)  # Wait for collection to load
            print(f"✅ Scene collection ready: {SCENE_COLLECTION_NAME}")
            return True
            
        except Exception as e:
            print(f"❌ Failed to setup scene collection: {e}")
            return False
    
    def setup_scene_and_source(self):
        """Create ESP32-CAM scene and browser source"""
        print(f"🎬 Setting up scene: {SCENE_NAME}")
        
        try:
            # Get existing scenes
            scenes_response = self.client.get_scene_list()
            existing_scenes = [s['sceneName'] for s in scenes_response.scenes]
            
            # Create scene if doesn't exist
            if SCENE_NAME not in existing_scenes:
                print(f"   Creating scene: {SCENE_NAME}")
                self.client.create_scene(SCENE_NAME)
                time.sleep(0.5)
            
            # Switch to the scene
            self.client.set_current_program_scene(SCENE_NAME)
            time.sleep(0.5)
            
            # Check if source already exists
            items = self.client.get_scene_item_list(SCENE_NAME)
            source_exists = any(item['sourceName'] == SOURCE_NAME for item in items.scene_items)
            
            if not source_exists:
                print(f"   Creating browser source: {SOURCE_NAME}")
                
                # Browser source settings for ESP32-CAM stream
                source_settings = {
                    "url": self.browser_url,
                    "width": 800,
                    "height": 600,
                    "fps": 30,
                    "reroute_audio": False,
                    "restart_when_active": True,
                    "shutdown": False,
                    "css": "body { background-color: rgba(0, 0, 0, 0); margin: 0px auto; overflow: hidden; }"
                }
                
                # Create input (source) - using camelCase for obsws-python
                self.client.create_input(
                    sceneName=SCENE_NAME,
                    inputName=SOURCE_NAME,
                    inputKind="browser_source",
                    inputSettings=source_settings,
                    sceneItemEnabled=True
                )
                time.sleep(1)
                print(f"✅ Browser source created: {SOURCE_NAME}")
            else:
                print(f"   Browser source already exists, updating URL...")
                # Update existing source URL
                self.client.set_input_settings(
                    inputName=SOURCE_NAME,
                    inputSettings={"url": self.browser_url},
                    overlay=True
                )
            
            print(f"✅ Scene setup complete!")
            return True
            
        except Exception as e:
            print(f"❌ Failed to setup scene: {e}")
            import traceback
            traceback.print_exc()
            return False
    
    def refresh_browser_source(self):
        """Refresh the browser source"""
        try:
            print(f"🔄 Refreshing browser source...")
            self.client.press_input_properties_button(
                input_name=SOURCE_NAME,
                property_name="refreshnocache"
            )
            print("✅ Browser source refreshed")
            return True
        except Exception as e:
            # Try alternative refresh method
            try:
                # Toggle source visibility as fallback refresh
                items = self.client.get_scene_item_list(SCENE_NAME)
                for item in items.scene_items:
                    if item['sourceName'] == SOURCE_NAME:
                        item_id = item['sceneItemId']
                        self.client.set_scene_item_enabled(SCENE_NAME, item_id, False)
                        time.sleep(0.3)
                        self.client.set_scene_item_enabled(SCENE_NAME, item_id, True)
                        print("✅ Browser source toggled (refresh)")
                        return True
            except:
                pass
            print(f"⚠️ Could not refresh browser source: {e}")
            return False
    
    def start_virtual_camera(self):
        """Start OBS virtual camera"""
        print("📹 Starting virtual camera...")
        try:
            status = self.client.get_virtual_cam_status()
            if status.output_active:
                print("✅ Virtual camera already running")
            else:
                self.client.start_virtual_cam()
                time.sleep(1)
                print("✅ Virtual camera started!")
            return True
        except Exception as e:
            print(f"❌ Failed to start virtual camera: {e}")
            return False
    
    def get_frame_hash(self):
        """Get hash of current ESP32-CAM frame to detect freezes"""
        try:
            response = requests.get(self.stream_url, stream=True, timeout=3)
            bytes_data = bytes()
            
            for chunk in response.iter_content(chunk_size=4096):
                bytes_data += chunk
                
                # Find JPEG boundaries
                a = bytes_data.find(b'\xff\xd8')
                b = bytes_data.find(b'\xff\xd9')
                
                if a != -1 and b != -1:
                    jpg = bytes_data[a:b+2]
                    # Return hash of JPEG data
                    return hashlib.md5(jpg).hexdigest()
            
            return None
        except Exception as e:
            return None
    
    def check_stream_frozen(self):
        """Check if stream appears frozen"""
        current_hash = self.get_frame_hash()
        
        if current_hash is None:
            # Stream not accessible
            return True
        
        if current_hash == self.last_frame_hash:
            self.frozen_count += 1
        else:
            self.frozen_count = 0
            
        self.last_frame_hash = current_hash
        
        return self.frozen_count >= FREEZE_THRESHOLD
    
    def start_monitoring(self):
        """Start monitoring stream for freezes"""
        self.monitoring = True
        
        def monitor_loop():
            print(f"👁️ Starting stream monitor (checking every {FREEZE_CHECK_INTERVAL}s)...")
            
            while self.monitoring:
                time.sleep(FREEZE_CHECK_INTERVAL)
                
                if not self.monitoring:
                    break
                
                if self.check_stream_frozen():
                    print(f"⚠️ Stream appears frozen! Refreshing...")
                    self.refresh_browser_source()
                    self.frozen_count = 0
                    time.sleep(2)  # Wait after refresh
                    
            print("👁️ Stream monitor stopped")
        
        thread = threading.Thread(target=monitor_loop, daemon=True)
        thread.start()
        return thread
    
    def stop_monitoring(self):
        """Stop monitoring stream"""
        self.monitoring = False


# =============================================================================
# MAIN FUNCTION
# =============================================================================
def main():
    parser = argparse.ArgumentParser(description="OBS ESP32-CAM Auto Setup")
    parser.add_argument("--esp32-ip", default=DEFAULT_ESP32_IP, help="ESP32-CAM IP address")
    parser.add_argument("--obs-host", default=DEFAULT_OBS_HOST, help="OBS WebSocket host")
    parser.add_argument("--obs-port", type=int, default=DEFAULT_OBS_PORT, help="OBS WebSocket port")
    parser.add_argument("--obs-password", default=DEFAULT_OBS_PASSWORD, help="OBS WebSocket password")
    parser.add_argument("--no-monitor", action="store_true", help="Disable stream monitoring")
    args = parser.parse_args()
    
    print("=" * 60)
    print("🎥 OBS ESP32-CAM Auto Setup Script")
    print("=" * 60)
    print(f"ESP32-CAM IP: {args.esp32_ip}")
    print(f"OBS Server:   {args.obs_host}:{args.obs_port}")
    print("=" * 60)
    print()
    
    # Create controller
    controller = OBSController(
        host=args.obs_host,
        port=args.obs_port,
        password=args.obs_password,
        esp32_ip=args.esp32_ip
    )
    
    # Connect to OBS
    if not controller.connect():
        sys.exit(1)
    
    try:
        # Setup scene collection
        if not controller.setup_scene_collection():
            sys.exit(1)
        
        # Setup scene and source
        if not controller.setup_scene_and_source():
            sys.exit(1)
        
        # Start virtual camera
        controller.start_virtual_camera()
        
        # Start monitoring (optional)
        if not args.no_monitor:
            monitor_thread = controller.start_monitoring()
            
            print()
            print("=" * 60)
            print("✅ Setup complete! OBS is now streaming ESP32-CAM")
            print("   Virtual camera is active")
            print("   Stream monitor is running")
            print()
            print("   Press Ctrl+C to stop monitoring and exit")
            print("=" * 60)
            
            try:
                while True:
                    time.sleep(1)
            except KeyboardInterrupt:
                print("\n🛑 Stopping...")
                controller.stop_monitoring()
        else:
            print()
            print("=" * 60)
            print("✅ Setup complete! OBS is now streaming ESP32-CAM")
            print("   Virtual camera is active")
            print("=" * 60)
        
    finally:
        controller.disconnect()


if __name__ == "__main__":
    main()
