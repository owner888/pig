/**
 * ImageLightbox Component
 *
 * Fullscreen ambient image viewer with backdrop blur and smooth transitions.
 */
let activeLightbox = null;

export function openImageLightbox(src) {
  if (!src) return;
  closeImageLightbox();

  const overlay = document.createElement("div");
  overlay.className = "image-lightbox-overlay";
  overlay.innerHTML = `
    <button class="image-lightbox-close" title="Close (Esc)">✕</button>
    <div class="image-lightbox-content">
      <img src="${src}" class="image-lightbox-img" alt="Preview">
    </div>
  `;

  const close = () => {
    overlay.classList.add("closing");
    setTimeout(() => {
      overlay.remove();
      if (activeLightbox === overlay) activeLightbox = null;
    }, 200);
  };

  overlay.querySelector(".image-lightbox-close").addEventListener("click", close);
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay || e.target.classList.contains("image-lightbox-content")) {
      close();
    }
  });

  const onKeyDown = (e) => {
    if (e.key === "Escape") {
      close();
      window.removeEventListener("keydown", onKeyDown);
    }
  };
  window.addEventListener("keydown", onKeyDown);

  document.body.appendChild(overlay);
  activeLightbox = overlay;

  // trigger animation
  requestAnimationFrame(() => overlay.classList.add("open"));
}

export function closeImageLightbox() {
  if (activeLightbox) {
    activeLightbox.remove();
    activeLightbox = null;
  }
}

if (typeof window !== "undefined") {
  window.openImageLightbox = openImageLightbox;
}
