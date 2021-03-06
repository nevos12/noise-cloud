<?php

namespace App\Services\Music\SongModelManager;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\Music\SongDataFetcher\Contracts\SongDataFetcherInterface;
use App\Services\Music\SongModelManager\contracts\SongModelManagerInterface;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SongModelManager implements SongModelManagerInterface
{
    /**
     * @var SongDataFetcherInterface
     */
    protected $songDataFetcher;
    
    /**
     * @var array
     */
    protected $searchOptions;
    
    /**
     * SongModelManager constructor.
     *
     * @param SongDataFetcherInterface $songDataFetcher
     */
    public function __construct(SongDataFetcherInterface $songDataFetcher)
    {
        $this->songDataFetcher = $songDataFetcher;
    }
    
    /**
     * main method of this class
     * check if the song exist in the database
     * if not search it in the api and create a Song model
     *
     * @param array $options
     * @return Song|null
     */
    public function firstOrCreate(array $options)
    {
        $this->configureOptions($options);
    
        $song = $this->findSongInDB();
    
        if (!$song) {
            $song = $this->findAndCreateSongInApi();
        }
    
        return $song;
    }
    
    /**
     * configure the options
     *
     * @param array $options
     */
    protected function configureOptions(array $options)
    {
        $resolver = new OptionsResolver();
        
        $resolver
            ->setDefaults([ 'album' => null ])
            ->setRequired(['title', 'artist'])
            ->setAllowedTypes('title', 'string')
            ->setAllowedTypes('artist', 'string')
            ->setAllowedTypes('album', ['string', 'null']);
        
        $this->searchOptions = $resolver->resolve($options);
    }
    
    /**
     * find a song in the database
     *
     * @return Song|null
     */
    protected function findSongInDB()
    {
        return Song::where('name', $this->searchOptions['title'])
            ->whereHas('artist', function (Builder $builder) {
                $builder->where('name', $this->searchOptions['artist']);
            })
            ->first();
    }
    
    /**
     * find data and create Song model with all the related information
     *
     * @return Song
     */
    protected function findAndCreateSongInApi()
    {
        $songResponse = $this->songDataFetcher->getSongInfo(
            $this->searchOptions['title'],
            $this->searchOptions['artist']
        );
        
        if (!$songResponse || $songResponse->isEmpty()) {
            return null;
        }
        
        $artist = $this->createArtist($songResponse);
        $album = $this->createAlbum($songResponse, $artist);
        
        return $this->createSong($songResponse, $artist, $album);
    }
    
    /**
     * Create artist
     *
     * @param $songResponse
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function createArtist($songResponse)
    {
        $artist = Artist::firstOrCreate([
            'name' => $songResponse->artist
        ]);
        
        if ($songResponse->artistImage && !$artist->getFirstMedia()) {
            $artist->addMediaFromUrl($songResponse->artistImage)->toMediaCollection();
        }
        
        return $artist;
    }
    
    /**
     * Create Album
     *
     * @param $songResponse
     * @param Artist $artist
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function createAlbum($songResponse, Artist $artist)
    {
        if (!$songResponse->album) {
            return null;
        }
        
        $album = Album::firstOrCreate([
            'name' => $songResponse->album,
            'artist_id' => $artist->id,
        ]);
        
        if ($songResponse->albumImage && !$album->getFirstMedia()) {
            $album->addMediaFromUrl($songResponse->albumImage)->toMediaCollection();
        }
        
        return $album;
    }
    
    /**
     * Create song
     *
     * @param $songResponse
     * @param Artist $artist
     * @param Album|null $album
     * @return Song
     */
    protected function createSong($songResponse, Artist $artist, Album $album = null)
    {
        return Song::create([
            'name' => $songResponse->title,
            'artist_id' => $artist->id,
            'album_id' => $album ? $album->id : null,
        ]);
    }
}