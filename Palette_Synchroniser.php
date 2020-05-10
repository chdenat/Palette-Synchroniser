<?php

/*
 * Palette Synchroniser
 *
 * This class a CSS file to retrive specific CSS variables to render the right palette for blocks, ACF or legacy Tiny MCE editor.
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
 * Version: 1.0.1
 *
 */

namespace NOLEAM\CSS;


use Exception;
use RuntimeException;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;

class Palette_Synchroniser {

	private const CLASS_NAME = 'Palette Synchroniser';

	/**
	 * @var array of strings - The duo of transients we manage
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
	 * Palette_Synchroniser constructor.
	 *
	 * @param array $settings - All the settings for the synchroniser
	 *
	 *          color_slugs:    @array of @string - colors slugs to parse (color-1, foreground-color, bg-color, text-color ...)
	 *          file:           path of the css file that contains the CSS :root  to parse
	 *          force :         force file parsing
	 *                          (default : false)
	 *          prefix:         prefix used to detects that variable is a name ( for {color-slug}, name = {prefix}-{color-slug}
	 *          restrict:      deny/allow color customization during edition
	 *                          (default : deny)
	 *          duration:       Duration between 2 CSS scans
	 *                          (default : one week)
	 *          legacy_mode:    'insert' the custom palette at the beginning or  'append' it at the end (if customize option set to true)
	 *                          (default : insert)
	 *          sync @array
	 *              blocks:     Gutenberg blocks palette synchronisation
	 *                          (default : true)
	 *              acf:        ACF palette synchronisation
	 *                          (default : true)
	 *              legacy:     TinyMCE palette synchronisation
	 *                          (default : true)
	 *
	 *          parser_path     path of the parser (should be end by /)
	 */
	public function __construct( array $settings ) {

		$defaults = [
			'color_slugs'    => null,
			'file'           => null,
			'force'          => false,
			'prefix'         => '',
			'restrict'       => true,
			'duration'       => MONTH_IN_SECONDS,
			'legacy_mode'    => 'insert',
			'sync_available' => [
				'blocks' => true,
				'acf'    => true,
				'legacy' => true,
			],
			'parser_path'    => PLUGIN_VENDORS,
		];

		// Merge defaults and provided settings
		$this->settings = (array) wp_parse_args( $settings, $defaults );

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
		// colors not provided
		if ( null === $this->settings['color_slugs'] ) {
			throw new RuntimeException( self::CLASS_NAME . ' : Colors settings are mandatory !' );
		}

		/**
		 * Step 1 : we get the palette from the css file or from the transients
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

		// It's time to set the palette and build the simple colors one
		$this->set_palette();
		$this->set_color_codes();

		/**
		 * Step 2 : Set the Block palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync_available']['blocks'] ) {
			add_action( 'after_setup_theme', [ $this, 'set_blocks_palette' ] );
		}
		/**
		 * Step 3 : if ACF installed, set the ACF color palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync_available']['acf'] ) {
			add_action( 'acf/input/admin_footer', [ $this, 'set_acf_palette' ] );
		}
		/**
		 * Step 4 : Set the TinyMCE Palette
		 *
		 * @since 1.0
		 *
		 */
		if ( $this->settings['sync_available']['legacy'] ) {
			if ( $this->settings['restrict'] ) {
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
								if ( in_array( $current, $this->settings['color_slugs'], true ) ) {
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

			// force color name to color css slug if it is not defined
			foreach ( $colors as $color ) {
				if ( ! isset( $color['name'] ) ) {
					$color['name'] = $color['slug'];
				}
			}

			return $colors;

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
		set_transient( $this->transients['date'], time(), $this->settings['duration'] - 1 );
		// and palette (for same period)
		set_transient( $this->transients['palette'], $this->palette, $this->settings['duration'] );
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
			$this->color_codes[] = $color['color'];
		}
	}

	/**
	 * suppress_legacy_color_picker
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
	 * Set the palette for the ACF Color Picker and made some change to Iris color picker
	 * if there is no customisation (hide useless elements).
	 *
	 * It inserts few jQuery code to the the job.
	 *
	 * @since   1.0
	 *
	 */
	public function set_acf_palette(): void {

		if ( empty( $this->color_codes ) ) {
			$this->set_color_codes();
		}

		$palette = array_unique( $this->color_codes ); // Suppress doublons
		ob_start();

		?>
        <script type="text/javascript">
            (function ($) {
                acf.add_filter('color_picker_args', function (args, $field) {
                    args.palettes = [<?php
						$array = array_keys( $palette );$last_key = end( $array );
						foreach ( $palette as $key => $color ) {
							echo "'$color'" . ( ( $key !== $last_key ) ? ',' : '' );
						}
						?>];
                    args.defaultColor = args.palettes[0];
					<?php if ($this->settings['restrict']) { ?>
                    $('.acf-color-picker .iris-picker-inner').hide();
                    $('.acf-color-picker .iris-picker.iris-border').height(15);
                    $('a.iris-palette').css({"min-width": 16, "min-height": 16});
					<?php } ?>
                    return args;
                });

            })(jQuery);
        </script>
		<?php
		echo ob_get_clean();
	}

	/**
	 * set_blocks_palette
	 *
	 * Used to define the palette for Gutenberg using add_theme_support core function
	 *
	 * @since 1.0
	 *
	 */
	public function set_blocks_palette(): void {
		// we use after_setup_theme to trigger the Gutenberg palette with/without customization
		add_theme_support( 'editor-color-palette', $this->palette );
		if ( $this->settings['restrict'] ) {
			add_theme_support( 'disable-custom-colors' );
		}
	}

	/**
	 * set_legacy_palette
	 *
	 * This filter sync the palette for TinyMCE by settings the right values :
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

		if ( ! $this->settings['restrict'] ) {
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
		if ( $this->settings['restrict'] ) {
			// Replace the palette if no customization
			return $this->set_custom_palette( [] );
		}
		// 1 - insertion of custom palette in first elements.
		$palette = apply_filters( 'noleam/insert_legacy_palette', $palette );

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
		$palette = array_merge( $palette, apply_filters( 'noleam/append_legacy_palette', $palette ) );

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
