<?php 

namespace bearonahill;

use bearonahill\Exception\MissingConfigException;
use duncan3dc\Sonos\Network;
use duncan3dc\Sonos\Tracks\Track;
use duncan3dc\Sonos\Tracks\TextToSpeech;
use duncan3dc\Sonos\Directory;
use duncan3dc\Sonos\Tracks\Stream;
use bearonahill\Database\PlaylistDatabase;
use bearonahill\Exception\PlaylistNotFoundException;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

class PlaylistManager
{
    /** @var callable */
    private $responseCallback;

    private $targetRoom;
    private $database;

    private $sonos;
    private $controller;

    private $lastPlaylist;

    public function __construct(PlaylistDatabase $database, TargetRoom $targetRoom)
    {
        $this->database = $database;
        $this->targetRoom = $targetRoom;

        $this->sonos = new Network();
    }

    public function initController()
    {
        try {
            $this->controller = $this->sonos->getControllerByRoom($this->targetRoom->getName());
        }
        catch(RuntimeException $ex){
            $this->respondWithMessage($e->getMessage());
            return false;
        }
        return true;
    }

    public function setResponseCallback(callable $callback)
    {
        $this->responseCallback = $callback;
    }

    public function useCard(string $code)
    {
        $playlist = null;

        try {
            $playlist = $this->database->getPlaylistFromCard($code);
        } catch (PlaylistNotFoundException $e) {
            $this->respondWithMessage($e->getMessage());
            //$this->playNotification($e->getMessage());
            $this->playNotification('error');
            return;
        }

        // Don't replay last played album
        if ($playlist == $this->lastPlaylist)
            return;
        $this->lastPlaylist = $playlist;

        $this->playNotification('select');

        if ($this->playlistIsAStream($playlist)) {

            $this->startStream($playlist);
            return;
        }

        $this->startQueue($playlist);
    }

    public function whatIsRunning()
    {
        if ($this->controller === null) {
            $this->respondWithMessage('Room is not playing anything');
            return;
        }

        $info = $this->controller->getMediaInfo();
        foreach ($info as $key => $value) {
            $this->respondWithMessage($key.': '.$value);
        }
    }

    private function playlistIsAStream($playlist)
    {
        if (substr($playlist, 0, 18) === "x-sonosapi-stream:") {
            return true;
        }

        if (substr($playlist, 0, 17) === "x-sonosapi-radio:") {
            return true;
        }
        
        return false;
    }

    private function startStream($uri)
    {
        $stream = new Stream($uri);
        $this->controller->getQueue()->clear();
        $this->controller->useStream($stream);
        $this->controller->play();
    }

    private function startQueue(string $playlistName)
    {
        $playlist = $this->sonos->getPlaylistByName($playlistName);
        $tracks = $playlist->getTracks();

        if (!is_array($tracks) || count($tracks) === 0) {
            $this->respondWithMessage('Added '.count($tracks).' to the playlist');
            //$this->playNotification('The selected playlist is empty or does not exist.');
            $this->playNotification('error');
            return;
        }

        if ($this->controller->isStreaming()) {
            $this->controller->useQueue();
        }

        $this->controller->getQueue()->clear()->addTracks($tracks);
        $this->respondWithMessage('Added '.count($tracks).' to the playlist');

        $this->controller->play();
    }

    private function respondWithMessage(string $message)
    {
        call_user_func($this->responseCallback, $message);
    }

    public function playNotification(string $message = "null")
    {
        // $smbUrl = null;
        // $smbFolder = null;

        // try {
        //     $smbFolder = $this->database->getConfig('smb.folder');
        //     $smbUrl = $this->database->getConfig('smb.url');
        // } catch (MissingConfigException $e) {
        //     $this->respondWithMessage($e->getMessage());
        // }

        // if (empty($smbFolder) || empty($smbUrl)) {
        //     $this->respondWithMessage('Audio notification not configured.');
        //     return;
        // }

        // $adapter = new Local($smbFolder);
        // $filesystem = new Filesystem($adapter);

        // $directory = new Directory($filesystem, $smbUrl, "tts");

        // $track = new TextToSpeech($message, $directory);

        $track = new Track("x-file-cifs://MUSICBOX/tracks/$message.mp3");
        $this->controller->interrupt($track);
    }
}
