let playing = true;

// Play/Pause button.
function playPause(id) {
  const pod_ep = document.querySelector('#'+id);
  let pIcon = document.querySelector('#play'+id);
  if (playing) {
    pod_ep.play();
    playing = false;
    pIcon.src = '/themes/custom/dynasty/icons/pod-pause.svg';
    document.querySelector('#back-'+id).classList.remove('tw-hidden');
    document.querySelector('#fwd-'+id).classList.remove('tw-hidden');
  } else {
    pod_ep.pause();
    playing = true;
    pIcon.src = '/themes/custom/dynasty/icons/pod-play.svg';
  }
}

// Skip forward button.
function skipForward(id) {
  const pod_ep = document.querySelector('#'+id);
  pod_ep.currentTime += 15.0;
}

// Skip backwards button.
function skipBackwards(id) {
  const pod_ep = document.querySelector('#'+id);
  if (pod_ep.currentTime > 15) {
    pod_ep.currentTime -= 15.0;
  }
  else {
    pod_ep.currentTime = 0;
  }
}
