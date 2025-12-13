/**
 * Frontend JavaScript for WC Loyalty Rewards
 */
(function() {
    'use strict';

    /**
     * Handle AJAX redemption form submission using event delegation
     */
    function handleRedeemSubmit(e) {
        const form = e.target.closest('form[data-wclr-ajax-redeem]');
        if (!form) {
            return;
        }

        e.preventDefault();

        const input = form.querySelector('#wclr_points_to_redeem');
        const button = form.querySelector('.wclr-redeem-button');
        const nonce = form.querySelector('[name="wclr_redeem_nonce"]').value;
        const points = parseInt(input.value, 10) || 0;
        const originalButtonText = button ? button.textContent : 'Apply';

        if (button) {
            button.disabled = true;
            button.textContent = 'Applying...';
        }

        // Make AJAX request
        const formData = new FormData();
        formData.append('action', 'wclr_redeem_points');
        formData.append('nonce', nonce);
        formData.append('points', points);

        fetch(wclrFrontend.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                // Update fragments if provided
                if (data.data && data.data.fragments && typeof jQuery !== 'undefined') {
                    jQuery.each(data.data.fragments, function(key, value) {
                        jQuery(key).replaceWith(value);
                    });
                }

                // Trigger WooCommerce cart/checkout update
                if (typeof jQuery !== 'undefined') {
                    // For checkout page - trigger update_checkout which will refresh fragments
                    if (jQuery('body').hasClass('woocommerce-checkout')) {
                        jQuery('body').trigger('update_checkout');
                    }
                    // For cart page - trigger cart update
                    else if (jQuery('body').hasClass('woocommerce-cart')) {
                        // Trigger cart update via AJAX
                        jQuery(document.body).trigger('wc_fragment_refresh');
                        // Also update cart totals
                        jQuery(document.body).trigger('wc_update_cart');
                    }
                }
            } else {
                alert(data.data?.message || 'An error occurred. Please try again.');
                if (button) {
                    button.disabled = false;
                    button.textContent = originalButtonText;
                }
            }
        })
        .catch(function(error) {
            console.error('Redemption error:', error);
            alert('An error occurred. Please try again.');
            if (button) {
                button.disabled = false;
                button.textContent = originalButtonText;
            }
        });
    }

    /**
     * Handle edit link click for manual input section
     */
    function handleEditLinkClick(e) {
        const editLink = e.target.closest('.wclr-edit-link');
        if (!editLink) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        const sectionId = editLink.getAttribute('aria-controls');
        const section = sectionId ? document.getElementById(sectionId) : editLink.closest('.wclr-redeem-block').querySelector('.wclr-manual-input-section');
        if (!section) {
            return;
        }

        const isExpanded = editLink.getAttribute('aria-expanded') === 'true';
        const newState = !isExpanded;
        editLink.setAttribute('aria-expanded', newState);

        if (newState) {
            section.style.display = 'block';
            // Focus on input when expanded
            const input = section.querySelector('#wclr_points_to_redeem');
            if (input) {
                setTimeout(function() {
                    input.focus();
                    input.select();
                }, 100);
            }
        } else {
            section.style.display = 'none';
        }
    }

    /**
     * Initialize when DOM is ready
     */
    function init() {
        // Use event delegation on document to handle dynamically added forms
        document.addEventListener('submit', handleRedeemSubmit, true);

        // Use event delegation for edit links (only add once)
        document.addEventListener('click', handleEditLinkClick, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

