let playing = true;
function playPause(id) {
  const pod_ep = document.querySelector('#'+id);
  let pIcon = document.querySelector('#play'+id);
  if (playing) {
    pod_ep.play();
    playing = false;
    pIcon.src = '/themes/custom/dynasty/icons/pod-pause.svg';
  } else {
    pod_ep.pause();
    playing = true;
    pIcon.src = '/themes/custom/dynasty/icons/pod-play.svg';
  }
}
