/**
 * @file
 * JavaScript for the voicemail recorder.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  // State constants
  const STATE = {
    IDLE: 'idle',
    RECORDING: 'recording',
    STOPPED: 'stopped',
    SENDING: 'sending',
    COMPLETE: 'complete',
    ERROR: 'error'
  };

  Drupal.behaviors.voicemailRecorder = {
    attach: function (context, settings) {
      once('voicemail-recorder', '#voicemail-recorder', context).forEach(function (container) {
        const recorder = new VoicemailRecorder(container, settings.dynastyVoicemail || {});
        recorder.init();
      });
    }
  };

  /**
   * VoicemailRecorder class
   */
  function VoicemailRecorder(container, settings) {
    this.container = container;
    this.settings = settings;
    this.maxDuration = settings.maxDuration || 120;
    this.csrfToken = settings.csrfToken || '';
    this.uploadUrl = settings.uploadUrl || '/api/voicemail/upload';

    // State
    this.currentState = STATE.IDLE;
    this.mediaRecorder = null;
    this.audioChunks = [];
    this.audioBlob = null;
    this.stream = null;
    this.timerInterval = null;
    this.recordingStartTime = null;
    this.recordingDuration = 0;

    // DOM elements
    this.elements = {};
  }

  VoicemailRecorder.prototype = {
    /**
     * Initialize the recorder
     */
    init: function () {
      // Check browser support
      if (!this.checkSupport()) {
        this.showElement('voicemail-unsupported');
        this.hideElement('voicemail-main');
        return;
      }

      this.cacheElements();
      this.bindEvents();
    },

    /**
     * Check if browser supports MediaRecorder
     */
    checkSupport: function () {
      return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    },

    /**
     * Cache DOM elements
     */
    cacheElements: function () {
      this.elements = {
        stateIdle: this.container.querySelector('#state-idle'),
        stateRecording: this.container.querySelector('#state-recording'),
        stateStopped: this.container.querySelector('#state-stopped'),
        stateSending: this.container.querySelector('#state-sending'),
        stateComplete: this.container.querySelector('#state-complete'),
        stateError: this.container.querySelector('#state-error'),
        btnStart: this.container.querySelector('#btn-start'),
        btnStop: this.container.querySelector('#btn-stop'),
        btnCancel: this.container.querySelector('#btn-cancel'),
        btnRestart: this.container.querySelector('#btn-restart'),
        btnSubmit: this.container.querySelector('#btn-submit'),
        btnAnother: this.container.querySelector('#btn-another'),
        btnRetry: this.container.querySelector('#btn-retry'),
        timer: this.container.querySelector('#recording-timer'),
        audioPreview: this.container.querySelector('#audio-preview'),
        form: this.container.querySelector('#voicemail-form'),
        nameInput: this.container.querySelector('#voicemail-name'),
        emailInput: this.container.querySelector('#voicemail-email'),
        errorMessage: this.container.querySelector('#error-message'),
        permissionDenied: this.container.querySelector('#voicemail-permission-denied')
      };
    },

    /**
     * Bind event listeners
     */
    bindEvents: function () {
      const self = this;

      this.elements.btnStart.addEventListener('click', function () {
        self.startRecording();
      });

      this.elements.btnStop.addEventListener('click', function () {
        self.stopRecording();
      });

      this.elements.btnCancel.addEventListener('click', function () {
        self.cancelRecording();
      });

      this.elements.btnRestart.addEventListener('click', function () {
        self.resetRecorder();
      });

      this.elements.form.addEventListener('submit', function (e) {
        e.preventDefault();
        self.submitVoicemail();
      });

      this.elements.btnAnother.addEventListener('click', function () {
        self.resetRecorder();
      });

      this.elements.btnRetry.addEventListener('click', function () {
        self.resetRecorder();
      });
    },

    /**
     * Start recording
     */
    startRecording: async function () {
      try {
        // Request microphone permission
        this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });

        // Determine best supported MIME type
        const mimeType = this.getSupportedMimeType();

        // Create MediaRecorder
        const options = mimeType ? { mimeType: mimeType } : {};
        this.mediaRecorder = new MediaRecorder(this.stream, options);
        this.audioChunks = [];

        this.mediaRecorder.ondataavailable = (event) => {
          if (event.data.size > 0) {
            this.audioChunks.push(event.data);
          }
        };

        this.mediaRecorder.onstop = () => {
          this.finalizeRecording();
        };

        // Start recording
        this.mediaRecorder.start(1000); // Collect data every second
        this.recordingStartTime = Date.now();
        this.startTimer();
        this.setState(STATE.RECORDING);

      } catch (err) {
        console.error('Error accessing microphone:', err);

        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
          this.showElement('voicemail-permission-denied');
        } else {
          this.showError('Could not access microphone. Please check your browser settings.');
        }
      }
    },

    /**
     * Get a supported MIME type for recording
     */
    getSupportedMimeType: function () {
      const types = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/wav'
      ];

      for (const type of types) {
        if (MediaRecorder.isTypeSupported(type)) {
          return type;
        }
      }

      return null;
    },

    /**
     * Stop recording
     */
    stopRecording: function () {
      if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
        this.mediaRecorder.stop();
        this.stopTimer();
        this.stopStream();
      }
    },

    /**
     * Cancel recording without saving
     */
    cancelRecording: function () {
      this.stopRecording();
      this.resetRecorder();
    },

    /**
     * Finalize the recording after stop
     */
    finalizeRecording: function () {
      // Create blob from chunks
      const mimeType = this.mediaRecorder.mimeType || 'audio/webm';
      this.audioBlob = new Blob(this.audioChunks, { type: mimeType });

      // Calculate duration
      this.recordingDuration = Math.round((Date.now() - this.recordingStartTime) / 1000);

      // Create preview URL
      const audioUrl = URL.createObjectURL(this.audioBlob);
      this.elements.audioPreview.src = audioUrl;

      this.setState(STATE.STOPPED);
    },

    /**
     * Start the recording timer
     */
    startTimer: function () {
      const self = this;
      this.timerInterval = setInterval(function () {
        const elapsed = Math.floor((Date.now() - self.recordingStartTime) / 1000);
        self.updateTimerDisplay(elapsed);

        // Auto-stop at max duration
        if (elapsed >= self.maxDuration) {
          self.stopRecording();
        }
      }, 100);
    },

    /**
     * Stop the timer
     */
    stopTimer: function () {
      if (this.timerInterval) {
        clearInterval(this.timerInterval);
        this.timerInterval = null;
      }
    },

    /**
     * Update timer display
     */
    updateTimerDisplay: function (seconds) {
      const mins = Math.floor(seconds / 60);
      const secs = seconds % 60;
      this.elements.timer.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
    },

    /**
     * Stop the media stream
     */
    stopStream: function () {
      if (this.stream) {
        this.stream.getTracks().forEach(function (track) {
          track.stop();
        });
        this.stream = null;
      }
    },

    /**
     * Submit the voicemail
     */
    submitVoicemail: async function () {
      const name = this.elements.nameInput.value.trim();
      const email = this.elements.emailInput.value.trim();

      // Validate
      if (!name) {
        this.elements.nameInput.focus();
        return;
      }

      if (!email || !this.isValidEmail(email)) {
        this.elements.emailInput.focus();
        return;
      }

      if (!this.audioBlob) {
        this.showError('No recording found. Please record a message first.');
        return;
      }

      this.setState(STATE.SENDING);

      // Prepare form data
      const formData = new FormData();
      formData.append('audio', this.audioBlob, 'voicemail.webm');
      formData.append('name', name);
      formData.append('email', email);
      formData.append('duration', this.recordingDuration);
      formData.append('csrf_token', this.csrfToken);

      // Include honeypot if present
      const honeypot = this.container.querySelector('#voicemail-website');
      if (honeypot) {
        formData.append('website', honeypot.value);
      }

      try {
        const response = await fetch(this.uploadUrl, {
          method: 'POST',
          body: formData
        });

        const data = await response.json();

        if (response.ok && data.success) {
          this.setState(STATE.COMPLETE);
        } else {
          this.showError(data.error || 'Failed to send voicemail.');
        }
      } catch (err) {
        console.error('Upload error:', err);
        this.showError('Network error. Please check your connection and try again.');
      }
    },

    /**
     * Validate email format
     */
    isValidEmail: function (email) {
      const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return re.test(email);
    },

    /**
     * Set the current state and update UI
     */
    setState: function (state) {
      this.currentState = state;

      // Hide all state elements
      const states = ['stateIdle', 'stateRecording', 'stateStopped', 'stateSending', 'stateComplete', 'stateError'];
      states.forEach(function (s) {
        this.elements[s].classList.add('hidden');
      }, this);

      // Show current state
      const stateMap = {
        [STATE.IDLE]: 'stateIdle',
        [STATE.RECORDING]: 'stateRecording',
        [STATE.STOPPED]: 'stateStopped',
        [STATE.SENDING]: 'stateSending',
        [STATE.COMPLETE]: 'stateComplete',
        [STATE.ERROR]: 'stateError'
      };

      const elementKey = stateMap[state];
      if (elementKey && this.elements[elementKey]) {
        this.elements[elementKey].classList.remove('hidden');
      }
    },

    /**
     * Show an error message
     */
    showError: function (message) {
      this.elements.errorMessage.textContent = message;
      this.setState(STATE.ERROR);
    },

    /**
     * Reset the recorder to initial state
     */
    resetRecorder: function () {
      this.stopTimer();
      this.stopStream();

      this.mediaRecorder = null;
      this.audioChunks = [];
      this.audioBlob = null;
      this.recordingStartTime = null;
      this.recordingDuration = 0;

      // Reset form
      this.elements.nameInput.value = '';
      this.elements.emailInput.value = '';

      // Reset timer display
      this.elements.timer.textContent = '0:00';

      // Clear audio preview
      if (this.elements.audioPreview.src) {
        URL.revokeObjectURL(this.elements.audioPreview.src);
        this.elements.audioPreview.src = '';
      }

      // Hide permission denied message
      this.hideElement('voicemail-permission-denied');

      this.setState(STATE.IDLE);
    },

    /**
     * Show an element by ID
     */
    showElement: function (id) {
      const el = this.container.querySelector('#' + id);
      if (el) {
        el.classList.remove('hidden');
      }
    },

    /**
     * Hide an element by ID
     */
    hideElement: function (id) {
      const el = this.container.querySelector('#' + id);
      if (el) {
        el.classList.add('hidden');
      }
    }
  };

})(Drupal, drupalSettings, once);
