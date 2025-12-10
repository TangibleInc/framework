<?php declare( strict_types=1 );

namespace Tangible\DataObject\Storage;

use Tangible\DataObject\PluralStorage;

class CustomPostTypeStorage implements PluralStorage {
    protected string $slug;

    public function __construct( string $slug ) {
        $this->slug = $slug;
    }

    public function register( string $slug, array $settings ): void {
        register_post_type( $slug, $settings );
    }

    private const META_KEY = '_tangible_data';

    public function insert( array $data ): int {
        $post_id = wp_insert_post( [
            'post_type'   => $this->slug,
            'post_status' => 'publish',
            'post_title'  => $data['title'] ?? '',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $data ) );

        return $post_id;
    }

    public function update( int $id, array $data ): void {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $this->slug ) {
            return;
        }

        if ( isset( $data['title'] ) ) {
            wp_update_post( [
                'ID'         => $id,
                'post_title' => $data['title'],
            ] );
        }

        update_post_meta( $id, self::META_KEY, wp_json_encode( $data ) );
    }

    public function delete( int $id ): void {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $this->slug ) {
            return;
        }

        wp_delete_post( $id, true );
    }

    public function find( int $id ): ?array {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $this->slug ) {
            return null;
        }

        $json = get_post_meta( $id, self::META_KEY, true );
        if ( ! $json ) {
            return [ 'title' => $post->post_title ];
        }

        return json_decode( $json, true );
    }

    public function all(): array {
        $posts = get_posts( [
            'post_type'      => $this->slug,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        $results = [];
        foreach ( $posts as $post ) {
            $json = get_post_meta( $post->ID, self::META_KEY, true );
            $data = $json ? json_decode( $json, true ) : [ 'title' => $post->post_title ];
            $data['id'] = $post->ID;
            $results[] = $data;
        }

        return $results;
    }
}
