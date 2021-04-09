let playing = true;
let pPause = document.querySelector('#play-pause');
function playPause(id) {
  if (playing) {
    const pod_ep = document.querySelector('#'+id);
    pod_ep.play(); //this will play the audio track
    playing = false;
  } else {
    pod_ep.pause();
    playing = true;
  }
}
