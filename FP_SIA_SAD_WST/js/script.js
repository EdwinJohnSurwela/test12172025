/**
 * Camera Access Helper for Local Network
 * Handles camera permissions and provides better error handling
 */

// Check if we're in a secure context
function isSecureContext() {
    // localhost is always considered secure
    if (window.location.hostname === 'localhost' || 
        window.location.hostname === '127.0.0.1') {
        return true;
    }
    // Check if using HTTPS
    if (window.location.protocol === 'https:') {
        return true;
    }
    // Check if browser considers this a secure context
    if (window.isSecureContext !== undefined) {
        return window.isSecureContext;
    }
    return false;
}

// Get camera stream with fallback options
async function getCameraStream(constraints = null) {
    const defaultConstraints = {
        video: {
            facingMode: 'environment',
            width: { ideal: 1280 },
            height: { ideal: 720 }
        },
        audio: false
    };

    const finalConstraints = constraints || defaultConstraints;

    try {
        // Try to get camera access
        const stream = await navigator.mediaDevices.getUserMedia(finalConstraints);
        return { success: true, stream: stream };
    } catch (error) {
        console.error('Camera access error:', error);
        
        // Provide specific error messages
        if (error.name === 'NotAllowedError') {
            return {
                success: false,
                error: 'Camera permission denied. Please allow camera access in your browser settings.',
                errorType: 'permission'
            };
        } else if (error.name === 'NotFoundError') {
            return {
                success: false,
                error: 'No camera found on this device.',
                errorType: 'no_camera'
            };
        } else if (error.name === 'NotReadableError') {
            return {
                success: false,
                error: 'Camera is being used by another application.',
                errorType: 'in_use'
            };
        } else if (error.name === 'OverconstrainedError') {
            // Try with simpler constraints
            try {
                const simpleStream = await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: false
                });
                return { success: true, stream: simpleStream };
            } catch (e) {
                return {
                    success: false,
                    error: 'Camera constraints could not be satisfied.',
                    errorType: 'constraints'
                };
            }
        } else if (error.name === 'SecurityError' || error.message.includes('secure')) {
            return {
                success: false,
                error: getSecurityErrorMessage(),
                errorType: 'security'
            };
        } else {
            return {
                success: false,
                error: 'Unable to access camera: ' + error.message,
                errorType: 'unknown'
            };
        }
    }
}

// Get detailed security error message with instructions
function getSecurityErrorMessage() {
    const hostname = window.location.hostname;
    const protocol = window.location.protocol;
    
    let message = 'Camera access requires a secure connection.\n\n';
    
    if (protocol !== 'https:' && hostname !== 'localhost' && hostname !== '127.0.0.1') {
        message += 'You are accessing this page via: ' + protocol + '//' + hostname + '\n\n';
        message += 'To fix this, you can:\n';
        message += '1. Access via localhost: http://localhost/FP_SIA_SAD_WST/\n';
        message += '2. Use Chrome with insecure origins flag (see instructions below)\n';
        message += '3. Set up HTTPS on your server\n\n';
        message += 'For Chrome, run with this flag:\n';
        message += '--unsafely-treat-insecure-origin-as-secure="http://' + hostname + '"';
    }
    
    return message;
}

// Check camera availability before starting scanner
async function checkCameraAvailability() {
    // Check if mediaDevices API is available
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return {
            available: false,
            reason: 'Camera API not available. This may be due to accessing the page over an insecure connection (HTTP instead of HTTPS) on a non-localhost address.'
        };
    }

    // Check if we're in a secure context
    if (!isSecureContext()) {
        return {
            available: false,
            reason: getSecurityErrorMessage()
        };
    }

    // Try to enumerate devices
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(device => device.kind === 'videoinput');
        
        if (videoDevices.length === 0) {
            return {
                available: false,
                reason: 'No camera detected on this device.'
            };
        }
        
        return {
            available: true,
            devices: videoDevices
        };
    } catch (error) {
        return {
            available: false,
            reason: 'Unable to check camera availability: ' + error.message
        };
    }
}

// Display camera access instructions modal
function showCameraInstructions() {
    const hostname = window.location.hostname;
    const currentUrl = window.location.href;
    
    const modal = document.createElement('div');
    modal.id = 'cameraInstructionsModal';
    modal.innerHTML = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: white; border-radius: 15px; padding: 30px; max-width: 600px; max-height: 90vh; overflow-y: auto;">
                <h2 style="color: #dc3545; margin-bottom: 20px;">📷 Camera Access Required</h2>
                
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>⚠️ Current Access Method:</strong><br>
                    <code style="background: #eee; padding: 3px 8px; border-radius: 4px;">${currentUrl}</code>
                </div>
                
                <h3 style="margin-bottom: 10px;">Option 1: Use Localhost (Recommended)</h3>
                <p>Access the application via localhost instead:</p>
                <a href="http://localhost/FP_SIA_SAD_WST/php/student.php" 
                   style="display: inline-block; background: #28a745; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; margin: 10px 0;">
                    🔗 Open via Localhost
                </a>
                
                <h3 style="margin: 20px 0 10px;">Option 2: Enable Insecure Origins in Chrome</h3>
                <p>Create a Chrome shortcut with this target:</p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0; word-break: break-all;">
                    <code>"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" --unsafely-treat-insecure-origin-as-secure="http://${hostname}"</code>
                </div>
                
                <h3 style="margin: 20px 0 10px;">Option 3: Chrome Flags (Temporary)</h3>
                <ol style="margin-left: 20px;">
                    <li>Open Chrome and go to: <code>chrome://flags</code></li>
                    <li>Search for: "Insecure origins treated as secure"</li>
                    <li>Add: <code>http://${hostname}</code></li>
                    <li>Click "Relaunch" to restart Chrome</li>
                </ol>
                
                <button onclick="this.closest('#cameraInstructionsModal').remove()" 
                        style="width: 100%; padding: 15px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; margin-top: 20px;">
                    Close
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

// Export functions for use in other scripts
window.CameraHelper = {
    isSecureContext,
    getCameraStream,
    checkCameraAvailability,
    showCameraInstructions,
    getSecurityErrorMessage
};
