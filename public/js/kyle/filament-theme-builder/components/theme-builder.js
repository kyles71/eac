export default function filamentThemeBuilder(state = {}) {
    return {
        css: state.css || '',
        scheme: state.scheme || 'light',
        mode: state.mode || 'desktop',
        previewUrl: state.previewUrl || '',
        zoom: 1,
        resizeObserver: null,
        resizePreviewListener: null,
        resizeTopbarListener: null,
        topbarResizeObserver: null,

        init() {
            this.updateTopbarHeight()
            this.$nextTick(() => this.updatePreviewZoom(this.mode))

            this.resizeObserver = new ResizeObserver(() => {
                this.updatePreviewZoom(this.mode)
            })

            if (this.$refs.frameWrap) {
                this.resizeObserver.observe(this.$refs.frameWrap)
            }

            this.resizePreviewListener = () => this.updatePreviewZoom(this.mode)
            this.resizeTopbarListener = () => this.updateTopbarHeight()

            window.addEventListener('resize', this.resizePreviewListener)
            window.addEventListener('resize', this.resizeTopbarListener)

            const topbar = document.querySelector('.fi-topbar')

            if (topbar) {
                this.topbarResizeObserver = new ResizeObserver(() => this.updateTopbarHeight())
                this.topbarResizeObserver.observe(topbar)
            }
        },

        destroy() {
            this.resizeObserver?.disconnect()
            this.topbarResizeObserver?.disconnect()

            if (this.resizePreviewListener) {
                window.removeEventListener('resize', this.resizePreviewListener)
            }

            if (this.resizeTopbarListener) {
                window.removeEventListener('resize', this.resizeTopbarListener)
            }
        },

        updatePreview(css, scheme) {
            this.css = typeof css === 'string' ? css : this.css
            this.scheme = typeof scheme === 'string' ? scheme : this.scheme
            this.sendPreview()
        },

        applyCurrentPageTheme(css) {
            if (typeof css !== 'string') {
                return
            }

            let style = document.querySelector('style[data-filament-theme-builder-current-page]')

            if (!style) {
                style = document.querySelector('style[data-filament-theme-builder]')
            }

            if (!style) {
                style = document.createElement('style')
                style.setAttribute('data-filament-theme-builder-current-page', '')
                document.head.appendChild(style)
            }

            style.textContent = css
        },

        previewFrameStyle(mode) {
            this.mode = mode || this.mode
            this.$nextTick(() => this.updatePreviewZoom(this.mode))

            return {
                zoom: this.zoom,
            }
        },

        setPreviewMode(mode) {
            this.mode = mode === 'mobile' ? 'mobile' : 'desktop'
            this.$nextTick(() => {
                this.updatePreviewZoom(this.mode)
                this.sendPreview()
            })
        },

        updateTopbarHeight() {
            const topbar = document.querySelector('.fi-topbar')
            const height = topbar && getComputedStyle(topbar).display !== 'none'
                ? topbar.getBoundingClientRect().height
                : 0

            document.documentElement.style.setProperty('--fi-theme-builder-topbar-height', `${height}px`)
        },

        updatePreviewZoom(mode) {
            const wrap = this.$refs.frameWrap

            if (!wrap) {
                return
            }

            if (mode !== 'mobile') {
                wrap.style.width = ''
                this.zoom = Math.max((wrap.clientWidth - 2) / 1440, 0.1)

                return
            }

            const virtualWidth = 390
            const virtualHeight = 844
            const widthSource = wrap.parentElement?.clientWidth || wrap.clientWidth
            const availableWidth = Math.max(widthSource - 2, 1)
            const availableHeight = Math.max(wrap.clientHeight - 2, 1)
            const widthZoom = availableWidth / virtualWidth
            const heightZoom = availableHeight / virtualHeight

            this.zoom = Math.min(1, widthZoom, heightZoom)
            wrap.style.width = `${Math.ceil(virtualWidth * this.zoom) + 2}px`
        },

        sendPreview() {
            const frame = this.$refs.preview

            if (!frame || !frame.contentWindow) {
                return
            }

            this.applyPreviewDirectly(frame)

            frame.contentWindow.postMessage({
                type: 'filament-theme-builder:preview',
                css: this.css,
                scheme: this.scheme,
            }, new URL(frame.src, window.location.href).origin)
        },

        applyPreviewDirectly(frame) {
            try {
                const doc = frame.contentDocument || frame.contentWindow.document

                if (!doc?.head || !doc?.documentElement) {
                    return
                }

                let style = doc.querySelector('style[data-filament-theme-builder-preview]')

                if (!style) {
                    style = doc.createElement('style')
                    style.setAttribute('data-filament-theme-builder-preview', '')
                    doc.head.appendChild(style)
                }

                style.textContent = this.css || ''
                doc.documentElement.classList.toggle('dark', this.scheme === 'dark')
                doc.documentElement.classList.add('fi-theme-builder-preview-active')
            } catch (error) {
                // Cross-origin previews are handled by postMessage when the bridge is present.
            }
        },
    }
}
