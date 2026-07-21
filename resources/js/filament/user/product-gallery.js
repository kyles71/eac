import PhotoSwipeLightbox from 'photoswipe/lightbox';

class ProductGallery extends HTMLElement {
    lightbox = null;

    initialization = null;

    pendingIndex = null;

    lastTrigger = null;

    connectedCallback() {
        if (this.initialization) {
            return;
        }

        this.addEventListener('click', this.handleGalleryClick, true);
        this.initialization = this.initialize();
    }

    disconnectedCallback() {
        this.removeEventListener('click', this.handleGalleryClick, true);
        this.lightbox?.destroy();
        this.lightbox = null;
        this.initialization = null;
        this.pendingIndex = null;
        this.lastTrigger = null;
        delete this.dataset.productGalleryReady;
    }

    handleGalleryClick = (event) => {
        const link = event.target.closest('[data-product-gallery-item]');

        if (! link || ! this.contains(link)) {
            return;
        }

        this.lastTrigger = link;

        if (this.lightbox) {
            return;
        }

        event.preventDefault();
        this.pendingIndex = this.links.indexOf(link);

        this.initialization?.then(() => {
            if (this.pendingIndex === null || ! this.lightbox) {
                return;
            }

            const index = this.pendingIndex;
            this.pendingIndex = null;
            this.lightbox.loadAndOpen(index);
        });
    };

    get links() {
        return Array.from(this.querySelectorAll('[data-product-gallery-item]'));
    }

    async initialize() {
        const links = this.links;

        if (links.length === 0) {
            return;
        }

        await Promise.all(links.map((link) => this.prepareDimensions(link)));

        if (! this.isConnected) {
            return;
        }

        const hasMultipleImages = links.length > 1;
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.lightbox = new PhotoSwipeLightbox({
            gallery: this,
            children: '[data-product-gallery-item]',
            pswpModule: () => import('photoswipe'),
            loop: false,
            wheelToZoom: true,
            imageClickAction: 'zoom',
            doubleTapAction: 'zoom',
            secondaryZoomLevel: 2.5,
            maxZoomLevel: 4,
            showHideAnimationType: prefersReducedMotion ? 'none' : 'zoom',
            zoomAnimationDuration: prefersReducedMotion ? 0 : 333,
            arrowKeys: hasMultipleImages,
            arrowPrev: hasMultipleImages,
            arrowNext: hasMultipleImages,
            counter: hasMultipleImages,
            indexIndicatorSep: ' of ',
            trapFocus: true,
            returnFocus: true,
            errorMsg: 'This image could not be loaded.',
            paddingFn: (viewportSize) => ({
                top: 48,
                right: 0,
                bottom: hasMultipleImages ? (viewportSize.x < 640 ? 92 : 104) : 24,
                left: 0,
            }),
        });

        this.lightbox.on('destroy', () => {
            const trigger = this.lastTrigger;

            requestAnimationFrame(() => {
                if (trigger?.isConnected) {
                    trigger.focus({ preventScroll: true });
                }
            });
        });

        if (hasMultipleImages) {
            this.registerThumbnailRail(links);
        }

        this.lightbox.init();
        this.dataset.productGalleryReady = 'true';
    }

    async prepareDimensions(link) {
        const image = link.querySelector('img');

        if (! image) {
            link.dataset.pswpWidth = '1';
            link.dataset.pswpHeight = '1';

            return;
        }

        if (! image.complete) {
            await new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }

        link.dataset.pswpWidth = String(image.naturalWidth || 1);
        link.dataset.pswpHeight = String(image.naturalHeight || 1);
    }

    registerThumbnailRail(links) {
        this.lightbox.on('uiRegister', () => {
            this.lightbox.pswp.ui.registerElement({
                name: 'product-gallery-thumbnails',
                className: 'eac-product-gallery-thumbnails',
                appendTo: 'root',
                onInit: (element, photoSwipe) => {
                    element.setAttribute('aria-label', 'Choose an image');
                    element.setAttribute('role', 'navigation');

                    const buttons = links.map((link, index) => {
                        const sourceImage = link.querySelector('img');
                        const button = document.createElement('button');
                        const thumbnail = document.createElement('img');
                        const imageName = sourceImage?.alt || `Image ${index + 1}`;

                        button.type = 'button';
                        button.className = 'eac-product-gallery-thumbnail';
                        button.dataset.productGalleryThumbnail = String(index);
                        button.setAttribute('aria-label', `View ${imageName}`);
                        button.addEventListener('click', (event) => {
                            event.stopPropagation();
                            photoSwipe.goTo(index);
                        });

                        thumbnail.alt = '';
                        thumbnail.src = sourceImage?.currentSrc || sourceImage?.src || link.href;
                        button.appendChild(thumbnail);
                        element.appendChild(button);

                        return button;
                    });

                    const updateActiveThumbnail = () => {
                        buttons.forEach((button, index) => {
                            const isActive = index === photoSwipe.currIndex;
                            button.setAttribute('aria-current', isActive ? 'true' : 'false');
                        });

                        const activeButton = buttons[photoSwipe.currIndex];
                        activeButton?.scrollIntoView({
                            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                            block: 'nearest',
                            inline: 'center',
                        });
                    };

                    photoSwipe.on('change', updateActiveThumbnail);
                    updateActiveThumbnail();
                },
            });
        });
    }
}

if (! customElements.get('eac-product-gallery')) {
    customElements.define('eac-product-gallery', ProductGallery);
}
