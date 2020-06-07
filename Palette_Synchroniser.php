<?php

/*
 * Palette Synchroniser
 *
 * This class a CSS file to retrieve specific CSS variables to render the right palette for blocks, ACF, Customizer or legacy Tiny MCE editor.
 *
 * By default, the color choices are restricted to the defined palette but it is possible to change
 * this behaviour by settings (see constructor).
 *
 * The scan uses Sabberworm CSS Parser : https://github.com/sabberworm/PHP-CSS-Parser
 *
 * Author:  Christian Denat for Noleam (contact@noleam.fr)
 *
 * github : https://github.com/chdenat/Palette-Synchroniser
 *
 * Version: 1.3.1
 *
 */

namespace NOLEAM\CSS;


use Exception;
use RuntimeException;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use function NOLEAM\DEV\debug_;

class Palette_Synchroniser {

	private const CLASS_NAME = 'Palette Synchroniser';
	private const SLUG = 'palette-synchroniser';

	/**
	 * @var array of strings - The trio of transients we manage
	 */
	private array $transients;
	/**
	 * @var array  see settings definition in contsructor
	 */
	private $settings;

	/**
	 * @var array : the palette definition [[name,slug,color]]
	 */
	private $palette;

	/**
	 * @var array - color codes
	 */
	private array $color_codes;
	/**
	 * @var mixed
	 */
	private $prefix_name;


	/**
	 * Palette_Synchroniser constructor.
	 *
	 * @param array $settings - All the settings for the synchroniser
	 *
	 *          color_slugs:    @string[]- colors slugs to parse (color-1, foreground-color, bg-color, text-color ...)
	 *          file:           path of the css file that contains the CSS :root  to parse
	 *          force :         force file parsing
	 *                          (default : false)
	 *          prefix:         prefix used to detects that variable is a name ( for {color-slug}, name = {prefix}-{color-slug}
	 *          strict:         deny/allow color customization during edition
	 *                          (default : true)
	 *          mimic:          mimic Gutenberg palette
	 *                          (default:true)
	 *          lifetime:       Duration between 2 CSS scans
	 *                          (default : one month)
	 *          legacy_mode:    'insert' the custom palette at the beginning or  'append' it at the end (if customize option set to true)
	 *                          (default : insert)
	 *          sync @array
	 *              blocks:     Gutenberg blocks palette synchronisation
	 *                          (default : true)
	 *              acf:        ACF palette synchronisation
	 *                          (default : true)
	 *              legacy:     TinyMCE palette synchronisation
	 *                          (default : true)
	 *              customizer
	 *              customiser: Customizer palette synchronisation
	 *                          (default : true )
	 *
	 *          parser_path     path of the parser (should be ended by /)
	 *
	 */
	public function __construct( array $settings ) {

		/**
		 * Defaults settings
		 */
		$defaults = [
			'color_slugs' => null,
			'file'        => null,
			'force'       => false,
			'prefix'      => '',
			'strict'      => true,
			'mimic'       => true,
			'lifetime'    => MONTH_IN_SECONDS,
			'legacy_mode' => 'insert',
			'sync'        => [
				'blocks'     => true,
				'acf'        => true,
				'legacy'     => true,
				'customizer' => true,
			],
			'parser_path' => PLUGIN_VENDORS,
		];

		// Merge defaults and user settings
		$this->settings = (array) wp_parse_args( $settings, $defaults );

		/*
		 * use also customiser key
		 * @since 1.1.1
		 */
		if ( isset( $this->settings['sync']['customiser'] ) ) {
			$this->settings['sync']['customizer'] = $this->$settings['sync']['customiser'];
		}
		/*
		 * duration is now obsolete, replaced by lifetime
		 */
		if ( isset( $this->settings['sync']['duration'] ) ) {
			$this->settings['sync']['lifetime'] = $this->$settings['sync']['duration'];
		}

		/**
		 * Step 0 : check if args are ok
		 *
		 * Some tests are done a,d if the fail, we throw a RunTime Exception
		 *
		 */

		// CSS file not provided
		if ( null === $this->settings['file'] ) {
			throw new RuntimeException( self::CLASS_NAME . ' : CSS file is mandatory !' );
		}
		// CSS File does not exist
		if ( ! file_exists( $this->settings['file'] ) ) {
			throw new RuntimeException( self::CLASS_NAME . ' : CSS file does not exist !' );
		}
		// color slugs not provided
		if ( null === $this->settings['color_slugs'] ) {
			throw new RuntimeException( self::CLASS_NAME . ' : Colors settings are mandatory !' );
		}

		/**
		 * we get the palette from the css file or from the transients
		 *
		 * @since 1.0
		 *
		 */

		// We use filename to ensure unicity of the transients content
		$this->transients = [
			'date'    => 'noleam-palette-parsing-' . $this->settings['file'],   // Last parsing date
			'palette' => 'noleam-palette-colors-' . $this->settings['file']     // colors palette
		];


		if ( isset( $this->settings['prefix'] ) ) {
			$this->prefix_name = $this->settings['prefix'];
		}

		/**
		 * It's time to set the palette and build the simple colors one
		 */
		$this->set_palette();
		$this->set_color_codes();

		/**
		 * We retrieve the URL. By default it is the current directory, but it's possible
		 * to overload it by using the noleam/palette_synchroniser/set_url filter
		 *
		 * @since 1.1
		 */
		$this->url = apply_filters( 'noleam/palette_synchroniser/set_url', plugin_dir_url( __FILE__ ) );

		/**
		 * We enqueue CSS and JS we'll use to deploy our own settings
		 *
		 * @since 1.1
		 */
		add_action( 'customize_controls_enqueue_scripts', [ $this, 'assets_enqueue' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets_enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets_enqueue' ] );


		/**
		 * Set the Block palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync']['blocks'] ) {
			add_action( 'after_setup_theme', [ $this, 'set_blocks_palette' ] );
			add_action( 'enqueue_block_assets', [ $this, 'gutenberg_palette_css_enqueue' ] );
		}

		/**
		 * if ACF installed, set the ACF color palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync']['acf'] ) { //}
			add_action( 'acf/input/admin_footer', [ $this, 'set_acf_palette' ] );
			add_action( 'acf/input/admin_footer', [ $this, 'add_mimic_to_acf' ] );
		}

		/**
		 * Set the Iris Customizer color palette
		 *
		 * @since 1.1
		 *
		 */
		if ( $this->settings['sync']['customizer'] ) {
			// Due to non-intrusive method, we add a script in header and the other in footer
			add_action( 'customize_controls_print_scripts', [ $this, 'set_customizer_palette' ], 99 );
			add_action( 'customize_controls_print_footer_scripts', [ $this, 'add_mimic_to_customizer' ], 99 );
		}

		/**
		 * Set the TinyMCE Palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync']['legacy'] ) {
			if ( $this->settings['strict'] ) {
				// suppress the colorpicker access if we want to restrict it
				add_filter( 'tiny_mce_plugins', [ $this, 'suppress_legacy_color_picker' ] );
			}
			add_action( 'tiny_mce_before_init', [ $this, 'set_legacy_palette' ] );
		}

	}

	/**
	 * get_palette
	 *
	 * Get the palette from the right place
	 *
	 * @since 1.0
	 *
	 */
	private function set_palette(): void {
		if ( $this->need_parsing() ) {
			//Autoload for Sabberworm
			spl_autoload_register( function ( $class ) {
				$path = explode( '\\', $class );
				if ( 'Sabberworm' === (string) $path[0] ) {
					include_once $this->settings['parser_path'] . ( ( $this->settings['parser_path'] [ - 1 ] !== '/' ) ? '/' : '' ) . implode( '/', $path ) . '.php';
				}
			} );
			$this->palette = $this->get_palette_from_css_parsing();
			$this->save_palette();
		} else {
			$this->palette = $this->get_palette_from_DB();
		}
	}

	/**
	 *
	 * need_parsing
	 *
	 * Check if there are some changes in the css file that need a new parsing. Priority to force = true
	 *
	 * Changes check is based on the last modified date of the file or if date transient expired.
	 *
	 * @return bool
	 *
	 * @since 1.0
	 *
	 */
	private function need_parsing(): bool {
		// We check the date transient
		$last_parsing = get_transient( $this->transients['date'] );

		// Need parsing when CSS last modified date > last parsing date or transient expired or force set to true
		return ( $this->settings['force'] || $last_parsing === false || filemtime( $this->settings['file'] ) > $last_parsing );
	}

	/**
	 *
	 * get_palette_from_css_parsing
	 *
	 * Get the palette information from the file parsing
	 *
	 * @return array - the palette data
	 *
	 * @since 1.0
	 *
	 */
	private function get_palette_from_css_parsing(): array {
		$colors = [];
		// All css content
		$css_content = new Parser( file_get_contents( $this->settings['file'] ) );
		try {
			foreach ( $css_content->parse()->getContents() as $css ) {
				if ( $css instanceof DeclarationBlock ) {
					foreach ( $css->getSelectors() as $selector ) {
						// Our variables are defined in the :root selector
						if ( $selector->getSelector() === ':root' ) {
							foreach ( $css->getRules() as $rule ) {
								// We try to extract all $variables and all $prefix-$variables rules
								$value   = $rule->getValue();
								$current = substr( $rule->getRule(), 2 );
								if ( in_array( $current, $this->settings['color_slugs'] ) ) {
									// We find  some color defined, we save it to the colors palette
									$colors[ $current ]['slug']  = $current;
									$colors[ $current ]['color'] = is_string( $value ) ? $value : $value->__toString();
								} else if ( ! empty( $this->settings['prefix'] ) && strpos( $rule->getRule(), '--' . $this->settings['prefix'] . '-' ) === 0 ) {
									// We find a color name, we add it to the colors palette
									$current                    = substr( $rule->getRule(), 3 + strlen( $this->settings['prefix'] ) );
									$colors[ $current ]['name'] = is_string( $value ) ? $value : $value->__toString();
								}
							}

							break;
						}
					}
				}
			}

			// suppress orphans (with no slug)
			$colors = array_filter( $colors, function ( $color ) {
				return isset( $color['slug'] );
			} );

			// Prepare the palette (names are forced to Slug if they do not exist
			$palette = [];
			foreach ( $colors as $key => $color ) {
				$palette[ $key ]['slug']  = $color['slug'];
				$palette[ $key ]['color'] = $color['color'];
				if ( ! isset( $color['name'] ) ) {
					$palette[ $key ]['name'] = ucfirst( $color['slug'] );
				} else {
					$palette[ $key ]['name'] = $color['name'];
				}
			}

			return $palette;

		} catch ( Exception $e ) {
			throw new RuntimeException( self::CLASS_NAME . $e->getTraceAsString() );
		}

	}

	/**
	 * save_palette
	 *
	 * Save the palette and parsing timestamp in transients
	 *
	 * @since 1.0
	 *
	 */
	private function save_palette(): void {
		// we save timestamp for a period (-1 second)
		set_transient( $this->transients['date'], time(), $this->settings['lifetime'] - 1 );
		// and palette (for same period)
		set_transient( $this->transients['palette'], $this->palette, $this->settings['lifetime'] );
	}

	/**
	 * Get the palette information from the dedicated transient.
	 *
	 * @return array - the palette data
	 *
	 * @since   1.0
	 *
	 */
	private function get_palette_from_DB(): ?array {
		if ( is_array( $palette = get_transient( $this->transients['palette'] ) ) ) {
			return $palette;
		}

		return null;
	}

	/**
	 *  set_color_codes
	 *
	 * Set an array that contains the color codes
	 *
	 * @since   1.0
	 *
	 */
	private function set_color_codes(): void {
		foreach ( $this->palette as $color ) {
			$this->color_codes[] = [ $color['slug'], $color['name'], $color['color'] ];
		}
	}

	/**
	 * assets_enqueue
	 *
	 * Action triggered by customize_controls_enqueue_scripts and admin_enqueue_scripts for the backend
     * but also wp_enqueue_script for the frontend
	 *
	 * Enqueue CSS and Js but also pass somevariables to JS
	 *
	 * @since 1.1
	 *
	 */
	public function assets_enqueue() {
		wp_enqueue_style( self::SLUG, $this->url . '/' . self::SLUG . '.css' );

		wp_register_script( self::SLUG, $this->url . '/' . self::SLUG . '.js', [ 'iris' ] );
		wp_localize_script( self::SLUG, 'noleam_ps', [
			'color_codes'       => $this->color_codes,
			'palette'           => $this->palette,
			'default_color'     => apply_filters( 'noleam/palette_synchroniser/set_default_color', $this->color_codes[0][2] ),
			'settings'          => $this->settings,
			'custom_color_text' => __( 'Custom color' ),
			'clean_text'        => __( 'Default' /*'Clear'*/ )
		] );
		wp_enqueue_script( self::SLUG );

	}

	/**
	 * gutenberg_palette_css_enqueue
	 *
	 * Action triggered by enqueue_block_editor_assets
	 *
	 * Enqueue specific CSS for Gutenberg to declare colors in frontend and backend
	 *
	 * @since 1.1
	 *
	 */
	public function gutenberg_palette_css_enqueue() {
		$handle = self::SLUG . '-gutenberg';
		wp_register_style( $handle, false, [ self::SLUG ] );
		wp_enqueue_style( $handle );
		// CSS can be modified with noleam/palette-synchroniser/gutenberg_css filter
		wp_add_inline_style( $handle, apply_filters( 'noleam/palette-synchroniser/gutenberg_css', $this->build_gutenberg_palette_css_snippet() ) );
	}

	/**
	 * build_gutenberg_palette_css_snippet
	 *
	 * Build snippet of CSS to declare the colors in cmopliance with Gutenberg.
	 *
	 * @return string
	 *
	 * @since 1.1
	 *
	 */
	private function build_gutenberg_palette_css_snippet(): string {
		$css = '';
		ob_start();
		foreach ( $this->palette as $color ) {
			?>
            /** <?= esc_attr( $color['name'] ) ?>**/
            .has-<?= esc_attr( $color['slug'] ) ?>-color { color: <?= esc_attr( $color['color'] ) ?> !important;}
            .has-<?= esc_attr( $color['slug'] ) ?>-background-color { background-color: <?= esc_attr( $color['color'] ) ?>;}
			<?php
		}
		$css .= preg_replace( '/\s+/', '', ob_get_clean() ); //miminize

		return wp_strip_all_tags( $css );
	}

	/**
	 * suppress_legacy_color_picker
	 *
	 * Filter triggered by tiny_mce_plugins
	 *
	 * Suppress the Tiny MCE color picker plugin.
	 *
	 * @param $plugins - list of all the plugins
	 *
	 * @return $plugins minus 'colorpicker' key
	 *
	 * @since 1.0
	 *
	 */
	function suppress_legacy_color_picker( $plugins ) {
		// https://wordpress.stackexchange.com/questions/272120/remove-custom-option-in-tinymce-colour-swatch
		foreach ( $plugins as $key => $plugin_name ) {
			if ( 'colorpicker' === $plugin_name ) {
				unset( $plugins[ $key ] );

				return $plugins;
			}
		}

		return $plugins;
	}


	/**
	 * set_acf_palette
	 *
	 * Action triggered by acf/input/admin_footer hook
	 *
	 * Set the palette colors for the ACF Color Picker
	 *
	 * It inserts few jQuery code to the the job.
	 *
	 * @since   1.0
	 *
	 */
	public function set_acf_palette(): void {
	    debug_('ACF');
		ob_start();
		?>
        <script type="text/javascript">
            let acf_picker = new Noleam_Iris('.acf-color-picker', true);
            acf.add_filter('color_picker_args', (args) => {
                colors = acf_picker.set_color_palette();
                args.palettes = colors;
                args.defaultColor = colors[0];
                return args;
            }, 1);
        </script>
		<?php
		echo ob_get_clean();
	}


	/**
	 * add_mimic_to_acf
	 *
	 * Action triggered by acf/render_field/type=color-picker hook
	 *
	 * Mimics Gutenberg if required
	 *
	 * It inserts few jQuery code to the the job.
	 *
	 * @since   1.0
	 *
	 */
	public function add_mimic_to_acf(): void {

		if ( ! $this->settings['sync']['acf'] || ! $this->settings['mimic'] ) {
			return;
		}
		ob_start();
		?>
        <script type="text/javascript">
            acf.addAction('show_field/type=color_picker', () => {
                acf_picker.mimics_gutenberg_color_picker();
            });
        </script>
		<?php
		echo ob_get_clean();
	}


	/**
	 * set_customizer_palette
	 *
	 * Action triggered by customize_controls_print_scripts hook
	 *
	 * Set the palette for all Customizer color Pickers
	 *
	 * It inserts few jQuery code to the the job.
	 *
	 * @since   1.1
	 *
	 */
	public function set_customizer_palette(): void {

		ob_start();
		?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) { //TODO replace jQuery
                let custo_pickers = new Noleam_Iris('.customize-control-color', false)
                $.wp.wpColorPicker.prototype.options = {
                    palettes: custo_pickers.set_color_palette(),
                    defaultColor: noleam_ps.default_color
                };
                console.log(noleam_ps.default_color)
            });
        </script>
		<?php
		echo ob_get_clean();
	}

	/**
	 * add_mimic_to_customizer
	 *
	 * Action triggered by customize_controls_print_footer_scripts hook
	 *
	 * Set the palette for all Customizer color Pickers
	 *
	 * It inserts few jQuery code to the the job.
	 *
	 * @since   1.1
	 *
	 */
	public function add_mimic_to_customizer(): void {

		ob_start();
		?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) { //TODO replace jQuery
                let custo_pickers = new Noleam_Iris('.customize-control-color', false);
                custo_pickers.mimics_gutenberg_color_picker();
            });
        </script>
		<?php
		echo ob_get_clean();
	}


	/**
	 * set_blocks_palette
	 *
	 * Action triggered by after_setup_theme
	 *
	 * Used to define the palette for Gutenberg using add_theme_support core function
	 *
	 * @since 1.0
	 *
	 */
	public function set_blocks_palette(): void {
		/**
		 * We set but also export the palette , so it can be used outside Palette_Synchroniser
		 */
		add_theme_support( 'editor-color-palette', $this->palette );
		if ( $this->settings['strict'] ) {
			add_theme_support( 'disable-custom-colors' );
		}
	}

	/**
	 * set_legacy_palette
	 *
	 * Filter triggered by tiny_mce_before_init
	 *
	 * Sync the palette for TinyMCE by settings the right values :
	 *      - the new palette
	 *      - rows and cols number
	 *
	 * @param $options array - TinyMCE options array
	 *
	 * @return array - TinyMCE options array with new values
	 *
	 * @since 1.0
	 *
	 */
	public function set_legacy_palette( $options ): array {

		if ( ! $this->settings['strict'] ) {
			// Customization : insert or append custom palette to the default palette
			if ( 'insert' === $this->settings['legacy_mode'] ) {
				add_filter( 'noleam/insert_legacy_palette', [ $this, 'set_custom_palette' ] );
			} else if ( 'append' === $this->settings['legacy_mode'] ) {
				add_filter( 'noleam/append_legacy_palette', [ $this, 'set_custom_palette' ] );
			}
		}

		$palette = $this->build_legacy_palette();

		$options['textcolor_map'] = json_encode( $palette );
		// Palette design : based on rows of max 8 (less if total < 8)
		$options['textcolor_rows'] = ceil( count( $palette ) / 16 );
		$options['textcolor_cols'] = min( count( $palette ) / 2, 8 );
		// If multiple of 8 we add a new col for the "no color" box.
		if ( ( count( $palette ) / 2 % 8 ) === 0 ) {
			$options['textcolor_rows'] += 1;
		}

		return $options;
	}

	/**
	 * build_legacy_palette
	 *
	 * This method builds the tiny MCE palette  (afaik, there is no other way to build the default palette .
	 *
	 * We trigger two filters  (only in case of customisation) :
	 *      one to insert a custom_palette at the beginning,
	 *      one to append custom colors at the end
	 * This filter can be useful if a custom palette already exists.
	 *
	 * @return array
	 *
	 * @since 1.0
	 *
	 */
	public function build_legacy_palette(): array {
		$palette = [];
		if ( $this->settings['strict'] ) {
			// Replace the palette if no customization
			return $this->set_custom_palette( [] );
		}
		// 1 - insertion of custom palette in first elements.
		$palette = apply_filters( 'noleam/palette_synchroniser/insert_legacy_palette', $palette );

		// 2 - Then add default palette
		$palette = array_merge( $palette, [
			'000000',
			'Black',
			"993300",
			'Burnt orange',
			"333300",
			"Dark olive",
			"003300",
			"Dark green",
			"003366",
			"Dark azure",
			"000080",
			"Navy Blue",
			"333399",
			"Indigo",
			"333333",
			"Very dark gray",
			"800000",
			"Maroon",
			"FF6600",
			"Orange",
			"808000",
			"Olive",
			"008000",
			"Green",
			"008080",
			"Teal",
			"0000FF",
			"Blue",
			"666699",
			"Grayish blue",
			"808080",
			"Gray",
			"FF0000",
			"Red",
			"FF9900",
			"Amber",
			"99CC00",
			"Yellow green",
			"339966",
			"Sea green",
			"33CCCC",
			"Turquoise",
			"3366FF",
			"Royal blue",
			"800080",
			"Purple",
			"999999",
			"Medium gray",
			"FF00FF",
			"Magenta",
			"FFCC00",
			"Gold",
			"FFFF00",
			"Yellow",
			"00FF00",
			"Lime",
			"00FFFF",
			"Aqua",
			"00CCFF",
			"Sky blue",
			"993366",
			"Red violet",
			"FFFFFF",
			"White",
			"FF99CC",
			"Pink",
			"FFCC99",
			"Peach",
			"FFFF99",
			"Light yellow",
			"CCFFCC",
			"Pale green",
			"CCFFFF",
			"Pale cyan",
			"99CCFF",
			"Light sky blue",
			"CC99FF",
			"Plum",
		] );
		// 3 - append custom palette
		$palette = array_merge( $palette, apply_filters( 'noleam/palette_synchroniser/append_legacy_palette', $palette ) );

		return $palette;
	}

	/**
	 * set_custom_palette
	 *
	 * Set the custom palette
	 *
	 * This method can be called directly when we replace the palette,
	 * or through the insert_legacy_palette/append_legacy_palette filters
	 *
	 * @param $palette : the current palette (for filter compliance)
	 *
	 * @return array : the custom palette
	 *
	 * @since 1.0
	 *
	 */
	public function set_custom_palette( $palette ): array {
		foreach ( $this->palette as $color ) {
			$palette[] = substr( $color['color'], 1 ); // we remove #
			$palette[] = $color['name'];
		}

		return $palette;
	}


}
