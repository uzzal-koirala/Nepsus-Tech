// Plays an EZVIZ live link (HLS) in a <video x-ref="video">. Usage: x-data="hlsPlayer('https://...m3u8')"
// hls.js is loaded only when a camera is opened, so other pages stay light.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('hlsPlayer', (url) => ({
        hls: null,
        loading: true,
        error: '',

        async init() {
            const video = this.$refs.video;
            video.addEventListener('playing', () => (this.loading = false));

            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = url; // Safari plays HLS on its own
            } else {
                const { default: Hls } = await import('hls.js');
                this.hls = new Hls({ liveSyncDuration: 3, lowLatencyMode: true });
                this.hls.loadSource(url);
                this.hls.attachMedia(video);
                this.hls.on(Hls.Events.ERROR, (_, data) => {
                    if (data.fatal) {
                        this.loading = false;
                        this.error = 'The live stream stopped. Close and open the camera again.';
                    }
                });
            }

            video.play().catch(() => {});
        },

        destroy() {
            this.hls?.destroy();
            this.hls = null;
        },
    }));
});
