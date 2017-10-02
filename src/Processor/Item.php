<?php namespace WP_CLI_Build\Processor;

use Symfony\Component\Filesystem\Filesystem;
use WP_CLI\Utils as WP_CLI_Utils;
use WP_CLI_Build\Helper\Build_File;
use WP_CLI_Build\Helper\Utils;
use WP_CLI_Build\Helper\WP_API;

class Item {

	private $build;

	public function __construct( $assoc_args = NULL ) {
		// Build file.
		$build_filename   = empty( $assoc_args['file'] ) ? 'build.yml' : $assoc_args['file'];
		$this->build      = new Build_File( $build_filename );
		$this->filesystem = new Filesystem();
	}

	// Starts processing items.
	public function run( $item_type = NULL ) {
		$result = FALSE;
		if ( ( $item_type == 'plugin' ) || ( $item_type == 'theme' ) ) {
			if ( ! empty( $this->build ) ) {
				$items = $this->build->get( $item_type . 's' );
				if ( ! empty( $items ) ) {
					$defaults = $this->build->get( 'defaults', $item_type . 's' );
					$result   = $this->process( $item_type, $items, $defaults );
				}
			}
		}

		return $result;
	}

	// Process item (plugin or theme).
	private function process( $type = NULL, $items = [], $defaults = [] ) {
		$result = FALSE;
		if ( ( $type == 'theme' || $type == 'plugin' ) && ( ! empty( $items ) ) ) {
			// Check if WP is installed.
			$wp_installed = Utils::wp_installed();
			$status       = FALSE;
			foreach ( $items as $item => $item_info ) {
        // Sets item latest version
        if ($item_info['version'] == '*' || $item_info['version'] == 'latest') {
          $item_info['version'] = $this->get_item_latest_version($type, $item, $item_info['version']);
        }
				// Download, install or activate the item depending on WordPress installation status.
				if ( $wp_installed ) {
					// Install if the plugin doesn't exist.
					$item_status = $this->status( $type, $item );
					if ( $item_status === FALSE ) {
						$status = $this->install( $type, $item, $item_info, $defaults );
					} // If the plugin is inactive, activate it.
					elseif ( $item_status === 'inactive' ) {
						$status = $this->activate( $type, $item, $item_info );
					} // Update if the version differs.
					elseif ( $item_status === 'active' ) {
					  // Get item info.
						if ( ! empty( $item_info['version'] ) ) {
              // Check if we need an update
							if ( $item_info['version'] != $this->version( $type, $item ) ) {
								$status = $this->update( $type, $item, $item_info );
							}
						}
					}
				} else {
					$status = $this->download( $type, $item, $item_info );
				}

				// Change result to TRUE, if something was downloaded, updated, installed or activated.
				if ( $status ) {
					$result = TRUE;
				}

			}
		}

		return $result;
	}

	// Download an item.
	private function download( $type = NULL, $item = NULL, $item_info = NULL ) {
		if ( ( $type == 'theme' || $type == 'plugin' ) && ( ! empty( $item ) ) && ( ! empty( $item_info ) ) ) {
			// Check if the item folder already exists or not.
			// If the folder exists and the version is the same as the build file, skip it.
			$folder = Utils::wp_path( 'wp-content/' . $type . 's/' . $item );
			$exists = $this->filesystem->exists( $folder );
			// If the folder doesn't exist, download the plugin.
			if ( ! $exists ) {
				Utils::line( "- Downloading %G$item%n (%Y{$item_info['version']}%n)" );
				$download_status = Utils::item_download( $type, $item, (string) $item_info['version'] );
				if ( $download_status === TRUE ) {
					Utils::line( ": done%n\n" );
				} else {
					Utils::line( ": %R{$download_status}%n\n" );
				}

				return TRUE;
			}
		}
	}

	// Install and activate an item.
	private function install( $type = NULL, $item = NULL, $item_info = NULL, $defaults = [] ) {
		// Processing text.
		$process = "- Installing %G$item%n";

		// Item install point.
		$install_point = empty( $item['url'] ) ? $item : $item['url'];

		// Item install arguments.
		$install_args = [];

		// Defaults merge.
		$defaults_code = [ 'version' => 'latest', 'force' => FALSE, 'activate' => FALSE, 'activate-network' => FALSE, 'gitignore' => FALSE ];
		$defaults      = array_merge( $defaults_code, $defaults );

		// Merge item info with the defaults (ixtem info will override defaults).
		$item_info = array_merge( $defaults, $item_info );

		// Item version.
		$process .= " (%Y{$item_info['version']}%n)";
		if ( ( ! empty( $item_info['version'] ) ) && ( $item_info['version'] != 'latest' ) ) {
			$install_args['version'] = $item_info['version'];
		}

		// Wether to force installation if the item is already installed.
		if ( ( ! empty( $item_info['force'] ) ) && ( $item_info['force'] ) ) {
			$install_args['force'] = TRUE;
		}

		// Activate it after installing.
		$install_args['activate'] = TRUE;

		// Active it on network after installing.
		if ( ( ! empty( $item_info['activate-network'] ) ) && ( $item_info['activate-network'] ) ) {
			$install_args['activate-network'] = TRUE;
		}

		// Processing message.
		Utils::line( $process );

		// Install item.
		$result = Utils::launch_self( $type, [ 'install', $install_point ], $install_args, FALSE, TRUE, [], FALSE, FALSE );

		// Print result.
		return Utils::result( $result );
	}

	// Activate an item.
	private function activate( $type = NULL, $item = NULL, $item_info = NULL ) {
		// Processing text.
		$process = "- Activating %G$item%n (%Y{$item_info['version']}%n)";

		// Processing message.
		Utils::line( $process );

		// Install item.
		$result = Utils::launch_self( $type, [ 'activate', $item ], [], FALSE, TRUE, [], FALSE, FALSE );

		// Print result.
		return Utils::result( $result );
	}

	// Activate an item.
	private function update( $type = NULL, $item = NULL, $item_info = NULL ) {

		// Current version.
		$old_version = $this->version( $type, $item );

		// Update/Downgrade.
		$action_label = ( version_compare( $old_version, $item_info['version'] ) === - 1 ) ? 'Updating' : 'Downgrading';

		// Processing text.
		$process = "- {$action_label} %G$item%n (%W{$old_version}%n => %Y{$item_info['version']}%n)";

		// Processing message.
		Utils::line( $process );

		// Install item.
		$result = Utils::launch_self( $type, [ 'update', $item ], [ 'version' => $item_info['version'] ], FALSE, TRUE, [], FALSE, FALSE );

		// Print result.
		return Utils::result( $result );
	}

	private function version( $type = NULL, $name = NULL ) {
		if ( ( $type == 'theme' || $type == 'plugin' ) && ( ! empty( $name ) ) ) {
			$result = Utils::launch_self( 'plugin', [ 'get', $name ], [ 'field' => 'version' ], FALSE, TRUE, [], FALSE, FALSE );
			if ( ! empty( $result->stdout ) ) {
				return trim( $result->stdout );
			}
		}

		return FALSE;
	}

	private function status( $type = NULL, $name = NULL ) {
		if ( ( $type == 'theme' || $type == 'plugin' ) && ( ! empty( $name ) ) ) {
			$result = Utils::launch_self( 'plugin', [ 'get', $name ], [ 'field' => 'status' ], FALSE, TRUE, [], FALSE, FALSE );
			if ( ! empty( $result->stdout ) ) {
				return trim( strtolower( $result->stdout ) );
			}
		}

		return FALSE;
	}

  private function get_item_latest_version( $type = NULL, $slug = NULL, $version = '*' ) {
    if ( ( $type == 'theme' || $type == 'plugin' ) && ( ! empty( $slug ) ) ) {
      $info_fn = $type . '_info';
      $info    = WP_API::$info_fn( $slug, $version, FALSE );
      if (!empty($info->version)) {
        return $info->version;
      }
    }

    return NULL;
  }

}