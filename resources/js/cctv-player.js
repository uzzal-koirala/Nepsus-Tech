// CCTV live player for the Nepsus CCTV Monitoring page.
// A stream only starts on Play (or when a camera is opened). The gateway address and a short-lived
// token come from the server per camera; camera passwords never reach the browser.
// hls.js is loaded on first play, so other pages stay light.

const MESSAGES = {
    unavailable: 'Camera unavailable. The office PC with the CCTV gateway may be off, or this device is not on the office network / Tailscale.',
    offline: 'The camera did not answer. It may be offline or its address or password on the gateway may be wrong.',
    denied: 'Access to this camera was refused.',
    failed: 'Playback stopped. Press Refresh to try again.',
    unsupported: 'This browser cannot play the live stream. Try Chrome, Edge, Firefox or Safari 17+.',
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cctvPlayer', (cameraId) => ({
        // idle | connecting | live | paused | offline | unavailable | denied | failed
        state: 'idle',
        message: '',
        hls: null,
        renewed: false,
        reported: false,

        init() {
            const video = this.$refs.video;
            video.addEventListener('playing', () => {
                this.state = 'live';
                if (!this.reported) {
                    this.reported = true;
                    this.$wire.markOnline(cameraId);
                }
            });

            // Never leave a stream running in the background.
            this.stopOnLeave = () => this.stop();
            document.addEventListener('livewire:navigating', this.stopOnLeave);
            window.addEventListener('pagehide', this.stopOnLeave);
        },

        destroy() {
            this.stop();
            document.removeEventListener('livewire:navigating', this.stopOnLeave);
            window.removeEventListener('pagehide', this.stopOnLeave);
        },

        get busy() {
            return this.state === 'connecting';
        },

        async play() {
            if (this.state === 'paused') {
                this.$refs.video.play().catch(() => {});
                return;
            }

            this.stop();
            this.state = 'connecting';
            this.message = '';

            let access;
            try {
                access = await this.$wire.access(cameraId);
            } catch {
                return this.fail('failed');
            }
            if (!access?.url) {
                return this.fail('denied', access?.error);
            }

            const { default: Hls } = await import('hls.js');
            if (!Hls.isSupported()) {
                return this.fail('failed', MESSAGES.unsupported);
            }

            const video = this.$refs.video;
            this.hls = new Hls({
                // The token goes in a header on every playlist and segment request
                // (hls.js calls this before opening the request, so open it here first).
                xhrSetup: (xhr, url) => {
                    xhr.open('GET', url, true);
                    xhr.setRequestHeader('Authorization', 'Bearer ' + access.token);
                },
                liveSyncDurationCount: 3,
                manifestLoadingMaxRetry: 1,
                manifestLoadingTimeOut: 20000,
            });
            this.hls.on(Hls.Events.MANIFEST_PARSED, () => video.play().catch(() => {}));
            this.hls.on(Hls.Events.ERROR, (_, data) => this.onError(data));
            this.hls.loadSource(access.url);
            this.hls.attachMedia(video);
        },

        onError(data) {
            if (!data.fatal) {
                return;
            }
            const code = data.response?.code ?? 0;

            if ((code === 401 || code === 403) && !this.renewed) {
                this.renewed = true; // token expired while watching: get a fresh one once
                this.stop();
                return this.play();
            }
            if (code === 401 || code === 403) return this.fail('denied');
            if (code === 0) return this.fail('unavailable');
            if (code === 404 || code >= 500) return this.fail('offline');
            this.fail('failed');
        },

        fail(state, message = null) {
            this.stop();
            this.state = state;
            this.message = message || MESSAGES[state] || MESSAGES.failed;
        },

        pause() {
            this.$refs.video.pause();
            this.state = 'paused';
        },

        refresh() {
            this.renewed = false;
            this.stop();
            this.play();
        },

        stop() {
            this.hls?.destroy();
            this.hls = null;
            const video = this.$refs.video;
            if (video) {
                video.pause();
                video.removeAttribute('src');
                video.load();
            }
            if (['live', 'paused', 'connecting'].includes(this.state)) {
                this.state = 'idle';
            }
        },

        fullscreen() {
            const frame = this.$refs.frame;
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (frame.requestFullscreen) {
                frame.requestFullscreen();
            } else {
                this.$refs.video.webkitEnterFullscreen?.(); // iPhone
            }
        },

        // Clicking a camera opens it fullscreen and starts it.
        open() {
            this.fullscreen();
            if (!['live', 'connecting'].includes(this.state)) {
                this.play();
            }
        },
    }));

    // Is the gateway (office PC) reachable from this device? Only checks the server, starts no camera.
    window.Alpine.data('cctvGateway', (url) => ({
        status: url ? 'checking' : 'missing', // checking | online | offline | insecure | missing

        init() {
            if (!url) return;
            if (location.protocol === 'https:' && url.startsWith('http:')) {
                this.status = 'insecure';
                return;
            }
            this.check();
        },

        async check() {
            this.status = 'checking';
            const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 5000));
            try {
                await Promise.race([fetch(url + '/', { mode: 'no-cors', cache: 'no-store' }), timeout]);
                this.status = 'online';
            } catch {
                this.status = 'offline';
            }
        },
    }));
});
