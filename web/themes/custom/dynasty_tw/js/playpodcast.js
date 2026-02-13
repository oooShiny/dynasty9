let playing = true;

// Play/Pause button.
function playPause(id) {
  const pod_ep = document.querySelector('#'+id);
  let pIcon = document.querySelector('#play'+id);
  const backBtn = document.querySelector('#back-'+id);
  const fwdBtn = document.querySelector('#fwd-'+id);

  if (playing) {
    pod_ep.play();
    playing = false;
    pIcon.src = '/themes/custom/dynasty_tw/icons/pod-pause.svg';
    // Slide buttons out from behind play button.
    backBtn.classList.remove('opacity-0', '-mr-6', 'scale-0');
    backBtn.classList.add('opacity-100', 'mr-0', 'scale-100');
    fwdBtn.classList.remove('opacity-0', '-ml-6', 'scale-0');
    fwdBtn.classList.add('opacity-100', 'ml-0', 'scale-100');
  } else {
    pod_ep.pause();
    playing = true;
    pIcon.src = '/themes/custom/dynasty_tw/icons/pod-play.svg';
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

// Expose functions globally for inline onclick handlers
window.playPause = playPause;
window.skipForward = skipForward;
window.skipBackwards = skipBackwards;
