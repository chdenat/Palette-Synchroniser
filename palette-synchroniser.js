/**
 * Noleam_Iris
 *
 * Add some customisation to any Iris based Color Picker in order
 * to make it more or less like a Gutenberg Color Picker.
 *
 * @author Christian Denat @ Noleam
 * @mail contact@self.fr
 *
 * @version : 1.1
 *
 * @param classes : the classes that identify the iris color picker.
 * @param acf : true if it is for ACF
 *
 * @constructor
 */

"use strict";

class Noleam_Iris {

    constructor(classes, acf) {

        this.classes = classes;
        this.palette = noleam_ps.palette;
        this.acf = acf;

        this.colors = this.set_color_palette();


        this.colorPickers = document.querySelectorAll(this.classes);

        // We add some classes to color pickers to ease CSS manipulations
        if (noleam_ps.settings.strict === true) {
            this.colorPickers.forEach((cp) => {
                cp.classList.add('strict-mode');
            });
        }
        if (noleam_ps.settings.mimic === true) {
            this.colorPickers.forEach((cp) => {
                cp.classList.add('mimic-mode');
            });
        }
    }

    /**
     * set_color_palette
     *
     * This function is used to set the iris color palette
     *
     * @since 1.0
     *
     */

    set_color_palette = () => {
        // Set the Iris color palette and default color
        let colors = [];
        noleam_ps.color_codes.forEach((color, key) => {
            colors[key] = this._hex_3_to_6(color[2]);
        });
        return colors;

    }

    /**
     * mimics_gutenberg_color_picker
     *
     * We try to transform iris to gutenberg color picker
     *
     * @since 1.1
     *
     */
    mimics_gutenberg_color_picker = function () {

        let self = this;
        if (noleam_ps.settings.strict === false) {
            this.colorPickers.forEach((cp) => {
                let p = cp.querySelector('.iris-picker');
                self._set_picker_height(cp);

                if (noleam_ps.settings.mimic) {
                    if (null !== p) {
                        p.style.height = '140px'
                        p.style.paddingBottom = '0';
                    }
                } else {
                    if (null !== p) {
                        p.style.height = '165px'
                        p.style.paddingBottom = '10px';
                    }
                }
            });

        }

        // Lot of changes to mimic the Gutenberg mode ...
        if (noleam_ps.settings.mimic) {

            self.colorPickers.forEach((cp) => {

                if (noleam_ps.settings.strict === false) {
                    /*
                     * add custom color link function if not already exists.
                     */
                    let custom = cp.querySelector('.noleam-custom-color');
                    if (null == custom) {
                        // add it
                        cp.querySelector('.iris-palette-container')
                            .insertAdjacentHTML('beforeend', '<a class="noleam-custom-color">' + noleam_ps.custom_color_text + '</a>');
                        // manage it
                        cp.querySelector('.noleam-custom-color').addEventListener('click', () => {
                            self._toggle(cp.querySelector('.iris-picker-inner'));
                            self._set_picker_height(cp);
                            cp.querySelector('.iris-picker').classList.toggle('hidden');
                        });
                    }
                }

                /*
                 * Move clear button if exists
                 */
                let clear = cp.querySelector('.wp-picker-clear');
                if (null !== clear) {
                    // First we remove it from where it is
                    clear.remove();
                    // Then re-create it to the right place
                    if (noleam_ps.settings.strict === false) {
                        cp.querySelector('.noleam-custom-color').insertAdjacentElement('beforebegin', clear);
                    } else {
                        cp.querySelector('.iris-palette-container').insertAdjacentElement('beforeend', clear);
                    }
                } else {
                    // We add some margin to push the 'Custom Color' link to the left
                    let custom = cp.querySelector('.noleam-custom-color');
                    if (null !== custom) {
                        custom.classList.add('push-left');
                    }
                }

                /*
                 * Open Iris but hide color picker
                 */
                cp.querySelector('button.wp-color-result').addEventListener('click', () => {
                    cp.querySelector('.iris-picker').classList.add('hidden');
                    self._hide(cp.querySelector('.iris-picker-inner'));
                    self._set_picker_height(cp);
                });

                /*
                 * Click on a color button : just toggle the check mark, Iris will do the rest.
                 */
                let palette = cp.querySelectorAll('a.iris-palette');
                palette.forEach((color) => {
                    // For color buttons we just toggle the check box
                    color.addEventListener('click', (e) => {
                        self._remove_current_color_check(cp.querySelector('a.iris-palette.current'));
                        self._add_color_check(color);
                    });

                });

                /*
                 * Now we'll fire the change event on the color input to trigger some palette check mark synchro.
                 */

                // Get changes on input element
                let input = cp.querySelector('input.wp-color-picker');
                input.addEventListener('input', self._update_check);
                input.addEventListener('change', self._update_check);

                // Manage events on Iris picker/slider components
                // According to targets and event type, we trigger the input change ...
                // TODO Check for Picker corners and  borders why sometimes we do not have change...
                let events = [
                    'click', 'mouseup', 'click', 'mouseup',
                    'click', 'mouseup', 'click', 'click',
                    'click'
                ];
                let targets = [
                    '.iris-square-value', '.iris-square-value', '.iris-square-inner', ' .ui-slider-handle',
                    '.iris-slider-offset', 'input.wp-color-picker', '.wp-picker-clear', '.wp-color-result',
                    '.wp-picker-default'
                ];
                events.forEach((event, index) => {
                    let target = cp.querySelector(targets[index]);
                    if (null !== target) {
                        target.addEventListener(event, (e) => {
                            input.dispatchEvent(new Event("change"));
                        })
                    }
                });
            });
        }
    }
    /**
     * _update_check
     *
     * Checks if we need to add a new check mark or hide the current
     *
     * @param event
     * @private
     *
     * @since 1.1
     */
    _update_check = (event) => {
        var self = this;
        let cp = event.target.parentNode.parentNode.nextSibling.querySelector('.iris-picker');
        let color = event.target.value;

        // We remove the existing one
        self._remove_current_color_check(cp.querySelector('a.iris-palette.current'));

        // Find if color is in the palette
        if (self.colors.includes(color)) {
            let palette = cp.querySelectorAll('a.iris-palette');
            for (let button of palette) {
                // if it is the case, let add the check mark to the right color
                if (self._hextorgb(color) === button.style.backgroundColor) {
                    self._add_color_check(button);
                    break;
                }
            }
        }
    }

    /**
     * _add_color_check
     *
     * Add check mark on a palette element
     *
     * @private
     * @param palette_button
     *
     * @since 1.1
     *
     */
    _add_color_check = (palette_button) => {
        // Bail early if no palette_button
        if (null === palette_button) {
            return
        }
        let self = this;
        // add new check
        palette_button.classList.add('current');
        //extract RGB in an array
        let rgb = (/rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/.exec(palette_button.style.backgroundColor)).slice(1, 4);
        palette_button.insertAdjacentHTML('beforeend', self._build_svg(rgb));
    }

    /**
     * _remove_current_color_check
     *
     * remove the current check
     *
     * @private
     * @param palette_button
     *
     * @since 1.1
     *
     */
    _remove_current_color_check = (palette_button) => {
        // Bail early if no palette_button
        if (null === palette_button) {
            return
        }
        // remove current class and childs (ie svg check)
        palette_button.classList.remove('current');
        palette_button.innerHTML = '';
    }

    /**
     * _contrast
     *
     * According to Contrast (YIQ vs 128), we return white or black color.
     *
     * @param rgb
     * @returns {string}
     * @private
     *
     * @since 1.1
     *
     */
    _contrast = (rgb) => {
        const threshold = 128;
        return this._rgbToYIQ(rgb) >= threshold ? '#000' : '#fff';
    }

    /**
     * _build_svg
     *
     * According to contrast with the background we get the Gutenberg check icon in the right color.
     *
     * @param rgb matrix [r,g,b]
     * @returns {string}
     * @private
     *
     * @since 1.1
     */
    _build_svg = (rgb) => {
        let self = this;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="'
            + self._contrast(rgb)
            + '" role="img" aria-hidden="true" focusable="false"><path d="M9 18.6L3.5 13l1-1L9 16.4l9.5-9.9 1 1z"></path></svg>'
    }

    /**
     * _hextorgb
     *
     * Convert 3 or 6 hex digit to rgb
     *
     * @param hex
     * @returns {string}
     * @private
     *
     * @since 1.1
     */
    _hextorgb = (hex) => {
        if (typeof hex !== 'string' || hex[0] !== '#') return 'transparent';
        const stringValues = (hex.length === 4)
            ? [hex.slice(1, 2), hex.slice(2, 3), hex.slice(3, 4)].map(n => `${n}${n}`)
            : [hex.slice(1, 3), hex.slice(3, 5), hex.slice(5, 7)];
        const intValues = stringValues.map(n => parseInt(n, 16));
        return `rgb(${intValues.join(', ')})`;
    }

    /**
     * _hex_3_to_6
     *
     * Convert a hex with 3 digits to 6
     *
     * @param hex
     * @returns {string|null}
     * @private
     *
     * @since 1.1
     */
    _hex_3_to_6 = (hex) => {
        if (typeof hex !== 'string' || hex[0] !== '#') return null;
        if (hex.length !== 4) {
            return hex;
        }
        return '#' + [hex.slice(1, 2), hex.slice(2, 3), hex.slice(3, 4)].map(n => `${n}${n}`).join('')
    }

    /**
     * _rgbToYIQ
     *
     * Transform RGB to YIQ
     *
     * https://hbfs.wordpress.com/2018/05/08/yuv-and-yiq-colorspaces-v/
     *
     * @param rgb matrix [r,g,b]
     * @returns {number}
     * @private
     *
     * @since 1.1
     */
    _rgbToYIQ = function (rgb) {
        return ((parseInt(rgb[0]) * 299) + (parseInt(rgb[1]) * 587) + (parseInt(rgb[2]) * 114)) / 1000;
    }

    /**
     * _hide
     *
     * hide an element
     *
     * @param elem
     * @private
     *
     * @since 1.1
     */
    _hide = function (elem) {
        elem.style.display = 'none';
    }

    /**
     * _show
     *
     * Show an element
     *
     * @param elem
     * @param type - block, inner-block, ...
     * @private
     *
     * @since 1.1
     */
    _show = function (elem, type = 'block') {
        elem.style.display = type;
    }

    /**
     * _toggle
     *
     * Toggle en element visibility
     *
     * @param elem
     * @param type
     * @private
     *
     * @since 1.1
     *
     */
    _toggle = function (elem, type = 'block') {
        let self = this;

        // if the element is visible, hide it
        if (elem.style.display !== 'none') {
            self._hide(elem);
            return;
        }
        // show the element
        self._show(elem, type);
    }

    /**
     *
     * _set_picker_height
     * Set the picker height according to some other elements (mimic mode, custom-color button ...)
     *
     * @param cp
     * @private
     *
     * @since 1.1
     *
     */
    _set_picker_height = (cp) => {

        let self = this;

        let size, per_row;
        if (noleam_ps.settings.mimic === true) {
            size = 36;
            per_row = 5;
        } else {
            size = 20;
            per_row = 8;
        }
        //resize color palette area

        const palette_height = size * Math.ceil(self.colors.length / per_row);
        const add_height = (!noleam_ps.settings.strict ? 24 : 0) + (self.colors.length > 1 ? 4 : 0);
        const _inner_height = 140;
        let picker = cp.querySelector('.iris-picker');
        let picker_height = palette_height + add_height;

        if (picker.classList.contains('hidden')) {
            picker_height += _inner_height;
        }
        picker.style.height = picker_height.toString() + 'px';
    }
}
