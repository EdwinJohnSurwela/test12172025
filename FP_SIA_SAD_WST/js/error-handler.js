/**
 * Frontend Error Handler with SweetAlert2 Integration
 * Library Hub - Centralized Error Handling
 */

const ErrorHandler = {
    // DepEd Theme Colors
    colors: {
        blue: '#1a4480',
        blueDark: '#0d2240',
        red: '#c41230',
        redDark: '#8b0a1e',
        success: '#28a745',
        warning: '#ffc107'
    },

    /**
     * Show error message with SweetAlert2
     * @param {string} message - Error message to display
     * @param {string} title - Optional title (default: 'Error')
     */
    showError: function(message, title = 'Error') {
        Swal.fire({
            icon: 'error',
            title: title,
            text: message,
            confirmButtonColor: this.colors.blue,
            customClass: {
                popup: 'error-popup-custom'
            }
        });
    },

    /**
     * Show success message with SweetAlert2
     * @param {string} message - Success message to display
     * @param {string} title - Optional title (default: 'Success')
     * @param {function} callback - Optional callback after modal closes
     */
    showSuccess: function(message, title = 'Success', callback = null) {
        Swal.fire({
            icon: 'success',
            title: title,
            text: message,
            confirmButtonColor: this.colors.blue,
            timer: 2000,
            timerProgressBar: true
        }).then(() => {
            if (typeof callback === 'function') {
                callback();
            }
        });
    },

    /**
     * Show warning message with SweetAlert2
     * @param {string} message - Warning message to display
     * @param {string} title - Optional title (default: 'Warning')
     */
    showWarning: function(message, title = 'Warning') {
        Swal.fire({
            icon: 'warning',
            title: title,
            text: message,
            confirmButtonColor: this.colors.blue
        });
    },

    /**
     * Show info message with SweetAlert2
     * @param {string} message - Info message to display
     * @param {string} title - Optional title (default: 'Information')
     */
    showInfo: function(message, title = 'Information') {
        Swal.fire({
            icon: 'info',
            title: title,
            text: message,
            confirmButtonColor: this.colors.blue
        });
    },

    /**
     * Show confirmation dialog
     * @param {string} message - Confirmation message
     * @param {string} title - Dialog title
     * @param {function} onConfirm - Callback if confirmed
     * @param {function} onCancel - Optional callback if cancelled
     */
    confirm: function(message, title = 'Are you sure?', onConfirm, onCancel = null) {
        Swal.fire({
            icon: 'question',
            title: title,
            text: message,
            showCancelButton: true,
            confirmButtonColor: this.colors.blue,
            cancelButtonColor: this.colors.red,
            confirmButtonText: 'Yes',
            cancelButtonText: 'No'
        }).then((result) => {
            if (result.isConfirmed) {
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                if (typeof onCancel === 'function') {
                    onCancel();
                }
            }
        });
    },

    /**
     * Show loading indicator
     * @param {string} message - Loading message (default: 'Please wait...')
     */
    showLoading: function(message = 'Please wait...') {
        Swal.fire({
            title: message,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    },

    /**
     * Hide loading indicator
     */
    hideLoading: function() {
        Swal.close();
    },

    /**
     * Show toast notification
     * @param {string} message - Toast message
     * @param {string} type - Type: 'success', 'error', 'warning', 'info'
     * @param {number} duration - Duration in milliseconds (default: 3000)
     */
    toast: function(message, type = 'info', duration = 3000) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: duration,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        Toast.fire({
            icon: type,
            title: message
        });
    },

    /**
     * Handle AJAX errors
     * @param {object} xhr - XMLHttpRequest object
     * @param {string} status - Error status
     * @param {string} error - Error message
     */
    handleAjaxError: function(xhr, status, error) {
        let message = 'An unexpected error occurred. Please try again.';

        if (xhr.status === 0) {
            message = 'Unable to connect to server. Please check your internet connection.';
        } else if (xhr.status === 401) {
            message = 'Session expired. Please log in again.';
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
        } else if (xhr.status === 403) {
            message = 'You do not have permission to perform this action.';
        } else if (xhr.status === 404) {
            message = 'The requested resource was not found.';
        } else if (xhr.status === 500) {
            message = 'Server error. Please try again later.';
        } else if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        this.showError(message);
        console.error('AJAX Error:', { status: xhr.status, error: error, response: xhr.responseText });
    },

    /**
     * Handle fetch API errors
     * @param {Response} response - Fetch response object
     * @returns {Promise} - Resolved or rejected promise
     */
    handleFetchResponse: async function(response) {
        if (!response.ok) {
            let message = 'An unexpected error occurred.';
            
            try {
                const data = await response.json();
                message = data.message || message;
            } catch (e) {
                // Response is not JSON
            }

            if (response.status === 401) {
                message = 'Session expired. Please log in again.';
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
            } else if (response.status === 403) {
                message = 'You do not have permission to perform this action.';
            } else if (response.status === 404) {
                message = 'The requested resource was not found.';
            } else if (response.status === 500) {
                message = 'Server error. Please try again later.';
            }

            this.showError(message);
            throw new Error(message);
        }

        return response.json();
    },

    /**
     * Validate form fields
     * @param {object} fields - Object with field names and values
     * @param {object} rules - Validation rules
     * @returns {object} - { valid: boolean, errors: array }
     */
    validateForm: function(fields, rules) {
        const errors = [];

        for (const [fieldName, fieldRules] of Object.entries(rules)) {
            const value = fields[fieldName];

            if (fieldRules.required && (!value || value.trim() === '')) {
                errors.push(`${fieldRules.label || fieldName} is required`);
                continue;
            }

            if (value && fieldRules.minLength && value.length < fieldRules.minLength) {
                errors.push(`${fieldRules.label || fieldName} must be at least ${fieldRules.minLength} characters`);
            }

            if (value && fieldRules.maxLength && value.length > fieldRules.maxLength) {
                errors.push(`${fieldRules.label || fieldName} must be no more than ${fieldRules.maxLength} characters`);
            }

            if (value && fieldRules.email && !this.isValidEmail(value)) {
                errors.push(`Please enter a valid email address`);
            }

            if (value && fieldRules.pattern && !fieldRules.pattern.test(value)) {
                errors.push(fieldRules.patternMessage || `${fieldRules.label || fieldName} format is invalid`);
            }

            if (value && fieldRules.match && value !== fields[fieldRules.match]) {
                errors.push(`${fieldRules.label || fieldName} does not match ${fieldRules.matchLabel || fieldRules.match}`);
            }
        }

        return {
            valid: errors.length === 0,
            errors: errors
        };
    },

    /**
     * Email validation helper
     * @param {string} email - Email address to validate
     * @returns {boolean}
     */
    isValidEmail: function(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    },

    /**
     * Show validation errors
     * @param {array} errors - Array of error messages
     */
    showValidationErrors: function(errors) {
        if (errors.length === 0) return;

        let html = '<ul style="text-align: left; margin: 0; padding-left: 20px;">';
        errors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        html += '</ul>';

        Swal.fire({
            icon: 'error',
            title: 'Validation Error',
            html: html,
            confirmButtonColor: this.colors.blue
        });
    },

    /**
     * Network status check
     */
    checkOnlineStatus: function() {
        if (!navigator.onLine) {
            this.showWarning('You appear to be offline. Some features may not work properly.', 'No Internet Connection');
            return false;
        }
        return true;
    },

    /**
     * Initialize network status listeners
     */
    initNetworkListeners: function() {
        window.addEventListener('online', () => {
            this.toast('Connection restored', 'success');
        });

        window.addEventListener('offline', () => {
            this.showWarning('You are now offline. Some features may not work.', 'Connection Lost');
        });
    },

    /**
     * Safe AJAX request wrapper with error handling
     * @param {object} options - jQuery AJAX options
     * @returns {Promise}
     */
    safeAjax: function(options) {
        const self = this;
        
        return new Promise((resolve, reject) => {
            if (!this.checkOnlineStatus()) {
                reject(new Error('No internet connection'));
                return;
            }

            $.ajax({
                ...options,
                success: function(response) {
                    if (response.success === false) {
                        self.showError(response.message || 'An error occurred');
                        reject(response);
                    } else {
                        resolve(response);
                    }
                },
                error: function(xhr, status, error) {
                    self.handleAjaxError(xhr, status, error);
                    reject({ xhr, status, error });
                }
            });
        });
    },

    /**
     * Safe fetch wrapper with error handling
     * @param {string} url - Request URL
     * @param {object} options - Fetch options
     * @returns {Promise}
     */
    safeFetch: async function(url, options = {}) {
        if (!this.checkOnlineStatus()) {
            throw new Error('No internet connection');
        }

        try {
            const response = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    ...options.headers
                }
            });

            return await this.handleFetchResponse(response);
        } catch (error) {
            if (error.name === 'TypeError') {
                this.showError('Unable to connect to server. Please check your connection.');
            }
            throw error;
        }
    }
};

// Initialize network listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    ErrorHandler.initNetworkListeners();
});

// Global error handler for uncaught JavaScript errors
window.onerror = function(message, source, lineno, colno, error) {
    console.error('Global Error:', { message, source, lineno, colno, error });
    // Optionally send to server for logging
    // ErrorHandler.showError('An unexpected error occurred. Please refresh the page.');
    return false;
};

// Global handler for unhandled promise rejections
window.addEventListener('unhandledrejection', function(event) {
    console.error('Unhandled Promise Rejection:', event.reason);
    // Optionally notify user
    // ErrorHandler.showError('An unexpected error occurred.');
});
