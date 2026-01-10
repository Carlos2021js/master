/* Player Upgrade (HLS + DASH hybrid) */
(function(){
  function initHybridPlayer(opts){
    var video = opts.video;
    var hlsUrl = opts.hlsUrl;
    var dashUrl = opts.dashUrl;
    var strategy = opts.strategy || 'auto';
    var buffer = opts.buffer || 3;

    function tryHLS(){
      if (window.Hls && Hls.isSupported() && hlsUrl){
        if (video.hls){ try{ video.hls.destroy(); }catch(e){} }
        var hls = new Hls({
          maxBufferLength: Math.max(5, buffer * 3),
          startPosition: buffer,
          enableWorker: true,
          lowLatencyMode: true
        });
        hls.loadSource(hlsUrl);
        hls.attachMedia(video);
        video.hls = hls;
        video.play().catch(function(err){ console.warn('HLS play error', err); });
        return true;
      }else if (video.canPlayType('application/vnd.apple.mpegurl')){
        video.src = hlsUrl;
        video.play().catch(function(err){ console.warn('Native HLS play error', err); });
        return true;
      }
      return false;
    }
    function tryDASH(){
      if (window.dashjs && dashUrl){
        if (video.dash){ try{ video.dash.reset(); }catch(e){} }
        var player = dashjs.MediaPlayer().create();
        player.initialize(video, dashUrl, true);
        player.updateSettings({
          streaming: {
            stableBufferTime: Math.max(5, buffer * 3),
            bufferPruningInterval: 10
          }
        });
        video.dash = player;
        return true;
      }
      return false;
    }
    if (strategy === 'hls'){
      return tryHLS();
    }else if (strategy === 'dash'){
      return tryDASH();
    }else{
      return tryHLS() || tryDASH();
    }
  }
  window.XUIHybridPlayer = { init: initHybridPlayer };
})();
