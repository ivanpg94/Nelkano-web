(function () {
  var root = document.querySelector('.nk-stream');
  if (!root) return;

  var form = document.querySelector('[data-stream-form]');
  var disconnectButton = document.querySelector('[data-stream-disconnect]');
  var stateNode = document.querySelector('[data-stream-state]');
  var sessionNode = document.querySelector('[data-stream-session]');
  var hintNode = document.querySelector('[data-stream-hint]');
  var logNode = document.querySelector('[data-stream-log]');
  var urlNode = document.querySelector('[data-stream-url]');
  var video = document.getElementById('stream-video');
  var fallbackImage = document.getElementById('stream-fallback-frame');
  var empty = document.querySelector('[data-video-empty]');
  var wsUrl = root.getAttribute('data-stream-ws-url') || '';
  var activeUrl = root.getAttribute('data-stream-active-url') || '';
  var frameUrl = root.getAttribute('data-stream-frame-url') || '';
  var iceConfigUrl = root.getAttribute('data-stream-ice-config-url') || '';
  var icePolicy = root.getAttribute('data-stream-ice-policy') || 'all';
  var dynamicIceServers = null;
  var dynamicIcePolicy = '';
  var iceConfigLoadedAt = 0;
  var iceConnectingTimer = null;
  var socket = null;
  var httpEndpoint = '';
  var polling = false;
  var framePolling = false;
  var frameSequence = 0;
  var frameVisibleLogged = false;
  var frameEmptyPolls = 0;
  var pollCursor = 0;
  var peer = null;
  var remoteStream = null;
  var pendingCandidates = [];
  var localCandidates = [];
  var currentSessionId = '';
  var webRtcConnected = false;

  function setState(value) {
    if (stateNode) stateNode.textContent = value;
  }

  function setSession(value) {
    if (sessionNode) sessionNode.textContent = value;
  }

  function setHint(value) {
    if (hintNode) hintNode.textContent = value;
  }

  function log(message) {
    if (!logNode) return;
    var item = document.createElement('li');
    item.textContent = new Date().toLocaleTimeString() + ' - ' + message;
    logNode.prepend(item);
  }

  function showWebRtcVideo() {
    webRtcConnected = true;
    clearIceConnectingTimer();
    if (fallbackImage) {
      fallbackImage.hidden = true;
      fallbackImage.removeAttribute('src');
    }
    if (empty) empty.hidden = true;
    frameVisibleLogged = false;
  }

  function send(type, sessionId, payload) {
    if (httpEndpoint) {
      var target = (type === 'answer' || type === 'ice-candidate' || type === 'disconnect' || type === 'receiver-waiting') ? 'android' : 'receiver';
      fetch(httpEndpoint + '/event', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: type, sessionId: sessionId, target: target, payload: payload || {} })
      }).catch(function (error) {
        log('No se pudo enviar evento HTTP: ' + error.message);
      });
      return;
    }
    if (!socket || socket.readyState !== WebSocket.OPEN) return;
    var message = { type: type };
    if (sessionId) message.sessionId = sessionId;
    if (payload) message.payload = payload;
    socket.send(JSON.stringify(message));
  }

  function closePeer() {
    clearIceConnectingTimer();
    pendingCandidates = [];
    localCandidates = [];
    webRtcConnected = false;
    if (peer) {
      peer.onicecandidate = null;
      peer.ontrack = null;
      peer.onconnectionstatechange = null;
      peer.close();
    }
    peer = null;
    remoteStream = null;
    if (video) video.srcObject = null;
    if (fallbackImage) {
      fallbackImage.hidden = true;
      fallbackImage.removeAttribute('src');
    }
    framePolling = false;
    frameSequence = 0;
    frameVisibleLogged = false;
    frameEmptyPolls = 0;
    if (empty) empty.hidden = false;
  }

  function disconnect(options) {
    options = options || {};
    var notifyAndroid = options.notifyAndroid !== false;
    var sessionId = currentSessionId;
    polling = false;
    if (notifyAndroid && httpEndpoint && sessionId) {
      send('disconnect', sessionId, { reason: 'receiver_disconnect' });
    }
    if (notifyAndroid && socket && socket.readyState === WebSocket.OPEN) {
      send('disconnect', sessionId, { reason: 'receiver_disconnect' });
      socket.close(1000, 'receiver_disconnect');
    } else if (socket && socket.readyState === WebSocket.OPEN) {
      socket.close(1000, 'receiver_reset');
    }
    socket = null;
    httpEndpoint = '';
    currentSessionId = '';
    closePeer();
    setState('Sin conectar');
    if (notifyAndroid) {
      log('Receptor desconectado');
    }
  }

  function flushCandidates() {
    if (!peer || !peer.remoteDescription) return;
    pendingCandidates.splice(0).forEach(function (candidate) {
      peer.addIceCandidate(candidate).catch(function (error) {
        log('ICE remoto rechazado: ' + error.message);
      });
    });
  }

  function createPeer(sessionId) {
    closePeer();
    peer = new RTCPeerConnection({
      iceServers: parseIceServers(),
      iceTransportPolicy: activeIcePolicy() === 'relay' ? 'relay' : 'all'
    });
    startIceConnectingTimer();
    remoteStream = new MediaStream();
    if (video) {
      video.muted = true;
      video.autoplay = true;
      video.playsInline = true;
      video.srcObject = remoteStream;
    }
    peer.addTransceiver('video', { direction: 'recvonly' });
    peer.addTransceiver('audio', { direction: 'recvonly' });
    peer.ontrack = function (event) {
      if (video) {
        if (event.streams && event.streams[0]) {
          video.srcObject = event.streams[0];
        } else if (remoteStream && !remoteStream.getTracks().some(function (track) { return track.id === event.track.id; })) {
          remoteStream.addTrack(event.track);
          video.srcObject = remoteStream;
        }
        event.track.onunmute = function () {
          video.play().catch(function () {});
        };
        video.play().catch(function () {});
      }
      showWebRtcVideo();
      log('Pista remota recibida: ' + event.track.kind);
    };
    peer.onicecandidate = function (event) {
      if (!event.candidate) return;
      var candidate = {
        sdpMid: event.candidate.sdpMid,
        sdpMLineIndex: event.candidate.sdpMLineIndex,
        candidate: event.candidate.candidate
      };
      localCandidates.push(candidate);
      send('ice-candidate', sessionId, { candidate: candidate });
    };
    peer.onconnectionstatechange = function () {
      var state = peer ? peer.connectionState : 'closed';
      setState('WebRTC: ' + state);
      log('Estado WebRTC: ' + state);
      if (state === 'connected') {
        clearIceConnectingTimer();
        showWebRtcVideo();
        setHint('WebRTC conectado. El fallback HTTP queda preparado por si la conexion cae.');
      } else if (state === 'failed' || state === 'disconnected') {
        webRtcConnected = false;
        setHint('WebRTC no pudo mantener la conexion directa. Usando video HTTP si esta disponible.');
        if (currentSessionId && frameUrl && !framePolling) {
          startFramePolling(currentSessionId);
        }
      }
    };
  }

  function clearIceConnectingTimer() {
    if (!iceConnectingTimer) return;
    window.clearTimeout(iceConnectingTimer);
    iceConnectingTimer = null;
  }

  function startIceConnectingTimer() {
    clearIceConnectingTimer();
    iceConnectingTimer = window.setTimeout(function () {
      iceConnectingTimer = null;
      if (!peer || webRtcConnected) return;
      var state = peer.connectionState || 'connecting';
      if (state === 'connected') return;
      setState('Video HTTP');
      setHint('WebRTC no conecto a tiempo. Usando fallback HTTP mientras la conexion directa sigue intentando mejorar.');
      log('WebRTC timeout; fallback HTTP activo');
    }, 12000);
  }

  function parseIceServers() {
    if (Array.isArray(dynamicIceServers) && dynamicIceServers.length > 0) {
      return dynamicIceServers;
    }
    var raw = root.getAttribute('data-stream-ice-servers') || '';
    if (raw) {
      try {
        var parsed = JSON.parse(raw);
        if (Array.isArray(parsed) && parsed.length > 0) return parsed;
      } catch (error) {
        log('Config ICE invalida: ' + error.message);
      }
    }
    return [{ urls: 'stun:stun.l.google.com:19302' }];
  }

  function activeIcePolicy() {
    return dynamicIcePolicy || icePolicy || 'all';
  }

  async function loadIceConfig(force) {
    var now = Date.now();
    if (!force && iceConfigLoadedAt && now - iceConfigLoadedAt < 240000) {
      return;
    }
    if (!iceConfigUrl) {
      iceConfigLoadedAt = now;
      return;
    }
    try {
      var response = await fetch(iceConfigUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      var data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + response.status));
      }
      if (Array.isArray(data.iceServers) && data.iceServers.length > 0) {
        dynamicIceServers = data.iceServers;
      }
      dynamicIcePolicy = data.iceTransportPolicy === 'relay' ? 'relay' : 'all';
      iceConfigLoadedAt = now;
      log('ICE config cargada: policy=' + activeIcePolicy() + ' servers=' + parseIceServers().length);
    } catch (error) {
      iceConfigLoadedAt = now;
      dynamicIceServers = null;
      dynamicIcePolicy = '';
      log('ICE config dinamica no disponible; usando fallback local: ' + error.message);
    }
  }

  function waitForIceGatheringComplete() {
    if (!peer || peer.iceGatheringState === 'complete') {
      return Promise.resolve();
    }
    return new Promise(function (resolve) {
      var timeout = window.setTimeout(function () {
        if (peer) {
          peer.removeEventListener('icegatheringstatechange', onChange);
        }
        resolve();
      }, 3000);
      function onChange() {
        if (!peer || peer.iceGatheringState !== 'complete') return;
        window.clearTimeout(timeout);
        peer.removeEventListener('icegatheringstatechange', onChange);
        resolve();
      }
      peer.addEventListener('icegatheringstatechange', onChange);
    });
  }

  function waitForLocalCandidates() {
    if (localCandidates.length > 0) {
      return Promise.resolve();
    }
    return new Promise(function (resolve) {
      var done = false;
      var timeout = window.setTimeout(finish, 1600);
      function finish() {
        if (done) return;
        done = true;
        window.clearTimeout(timeout);
        if (peer) {
          peer.removeEventListener('icegatheringstatechange', onChange);
        }
        resolve();
      }
      function onChange() {
        if (!peer || peer.iceGatheringState !== 'complete') return;
        window.setTimeout(finish, 250);
      }
      if (peer) {
        peer.addEventListener('icegatheringstatechange', onChange);
      }
    });
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  async function handleOffer(sessionId, offer) {
    if (!offer || !offer.sdp) {
      log('Offer vacia recibida');
      return;
    }
    if (!peer) {
      await loadIceConfig(false);
      createPeer(sessionId);
    }
    setState('Creando answer');
    await peer.setRemoteDescription(new RTCSessionDescription({
      type: offer.type || 'offer',
      sdp: offer.sdp
    }));
    flushCandidates();
    var answer = await peer.createAnswer();
    await peer.setLocalDescription(answer);
    log('ICE local tras setLocalDescription: ' + peer.iceGatheringState + ' / ' + localCandidates.length);
    await sleep(2500);
    await waitForLocalCandidates();
    await waitForIceGatheringComplete();
    log('ICE local antes de answer: ' + (peer ? peer.iceGatheringState : 'closed') + ' / ' + localCandidates.length);
    var localDescription = peer.localDescription || answer;
    send('answer', sessionId, {
      answer: {
        type: localDescription.type,
        sdp: localDescription.sdp
      },
      candidates: localCandidates.slice()
    });
    setState('Answer enviada');
    log('Answer enviada al Android');
  }

  function handleRemoteCandidate(candidate) {
    if (!candidate || !candidate.candidate) return;
    var ice = new RTCIceCandidate({
      sdpMid: candidate.sdpMid,
      sdpMLineIndex: candidate.sdpMLineIndex || 0,
      candidate: candidate.candidate
    });
    if (!peer || !peer.remoteDescription) {
      pendingCandidates.push(ice);
      return;
    }
    peer.addIceCandidate(ice).catch(function (error) {
      log('ICE remoto rechazado: ' + error.message);
    });
  }

  function handleSignalEvent(type, sessionId, payload) {
    payload = payload || {};
    if (type === 'offer') {
      handleOffer(sessionId, payload.offer).catch(function (error) {
        setState('Error WebRTC');
        log('Error creando answer: ' + error.message);
      });
      return;
    }
    if (type === 'ice-candidate') {
      handleRemoteCandidate(payload.candidate);
      return;
    }
    if (type === 'disconnect') {
      setState('Sesion cerrada');
      setSession('Abre el emulador en Android y pulsa Streaming.');
      log(payload.reason || 'Sesion cerrada');
      closePeer();
    }
  }

  function showHttpFrame(data) {
    if (!fallbackImage || !data || !data.image) return;
    if (webRtcConnected) return;
    fallbackImage.src = 'data:' + (data.mime || 'image/jpeg') + ';base64,' + data.image;
    fallbackImage.hidden = false;
    if (empty) empty.hidden = true;
    if (!frameVisibleLogged) {
      frameVisibleLogged = true;
      setState('Video HTTP');
      setHint('Video recibido desde Android. WebRTC seguira intentando mejorar la conexion si puede.');
      log('Video recibido por fallback HTTP');
    }
  }

  function startFramePolling(sessionId) {
    if (!frameUrl || !sessionId) return;
    framePolling = true;
    frameSequence = 0;
    frameVisibleLogged = false;
    frameEmptyPolls = 0;
    pollHttpFrames();
  }

  async function pollHttpFrames() {
    while (framePolling && frameUrl && currentSessionId) {
      try {
        var response = await fetch(frameUrl + '?sessionId=' + encodeURIComponent(currentSessionId) + '&since=' + frameSequence, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        var data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.message || ('HTTP ' + response.status));
        }
        if (data.frame && data.image) {
          frameEmptyPolls = 0;
          frameSequence = Math.max(frameSequence, Number(data.sequence || Date.now()));
          showHttpFrame(data);
        } else if (data.sequence) {
          frameSequence = Math.max(frameSequence, Number(data.sequence || frameSequence));
        } else {
          frameEmptyPolls++;
          if (frameEmptyPolls === 1 || frameEmptyPolls % 8 === 0) {
            setState('Esperando video HTTP');
            log('Esperando frames HTTP de Android');
          }
        }
        await sleep(fallbackImage && !fallbackImage.hidden ? 60 : 160);
      } catch (error) {
        var message = String(error && error.message ? error.message : '');
        if (message.toLowerCase().indexOf('sesion de streaming no encontrada') !== -1 || message.indexOf('404') !== -1) {
          framePolling = false;
          setState('Emision cerrada');
          setSession('Abre el emulador en Android y pulsa Streaming.');
          setHint('Drupal no encuentra la sesion activa. Vuelve a pulsar Streaming en Android.');
          log('Fallback HTTP: sesion no encontrada en Drupal');
          return;
        }
        log('Fallback HTTP fallo: ' + message);
        await sleep(1400);
      }
    }
  }

  async function connectHttp(sessionId, endpoint) {
    disconnect({ notifyAndroid: false });
    if (!sessionId || !endpoint) {
      setState('Sin emision activa');
      setSession('Abre el emulador en Android y pulsa Streaming.');
      return;
    }
    currentSessionId = sessionId;
    httpEndpoint = endpoint.replace(/\/$/, '');
    pollCursor = 0;
    polling = true;
    webRtcConnected = false;
    setState('Conectando WebRTC');
    setSession('Sesion enlazada con Android');
    if (urlNode) urlNode.textContent = httpEndpoint + '/events';
    log('Conectando por signaling HTTP con WebRTC y fallback HTTP.');
    await loadIceConfig(true);
    createPeer(sessionId);
    startFramePolling(sessionId);
    pollHttpEvents();
    send('receiver-waiting', sessionId, {});
  }

  async function pollHttpEvents() {
    while (polling && httpEndpoint && currentSessionId) {
      try {
        var response = await fetch(httpEndpoint + '/events?sessionId=' + encodeURIComponent(currentSessionId) + '&since=' + pollCursor + '&target=receiver', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        var data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.message || ('HTTP ' + response.status));
        }
        pollCursor = Math.max(pollCursor, Number(data.cursor || pollCursor));
        var events = Array.isArray(data.events) ? data.events : [];
        for (var i = 0; i < events.length; i++) {
          handleSignalEvent(events[i].type, currentSessionId, events[i].payload || {});
        }
        await sleep(700);
      } catch (error) {
        var message = String(error && error.message ? error.message : '');
        if (message.toLowerCase().indexOf('sesion de streaming no encontrada') !== -1 || message.indexOf('404') !== -1) {
          polling = false;
          httpEndpoint = '';
          currentSessionId = '';
          closePeer();
          setState('Emision cerrada');
          setSession('Abre el emulador en Android y pulsa Streaming.');
          setHint('La emision activa ya no existe. Vuelve a pulsar Streaming en Android.');
          log('Sesion de streaming cerrada');
          return;
        }
        setState('Error de signaling');
        log('Polling HTTP fallo: ' + message);
        await sleep(1400);
      }
    }
  }

  function connect(sessionId, pin) {
    disconnect({ notifyAndroid: false });
    if (!sessionId) {
      setState('Sin emision activa');
      setSession('Abre el emulador en Android y pulsa Streaming.');
      return;
    }
    currentSessionId = sessionId;
    httpEndpoint = '';
    setSession('Conectando con tu emision de Android');
    socket = new WebSocket(wsUrl);
    var activeSocket = socket;
    setState('Abriendo signaling');
    log('Conectando a ' + wsUrl);
    activeSocket.addEventListener('open', function () {
      if (socket !== activeSocket) return;
      setState('Uniendo sesion');
      loadIceConfig(true).then(function () {
        if (socket !== activeSocket) return;
        createPeer(sessionId);
        send('join-session', sessionId, { pin: pin });
      }).catch(function () {
        if (socket !== activeSocket) return;
        createPeer(sessionId);
        send('join-session', sessionId, { pin: pin });
      });
    });
    activeSocket.addEventListener('message', function (event) {
      if (socket !== activeSocket) return;
      var message;
      try {
        message = JSON.parse(event.data);
      } catch (error) {
        log('Mensaje invalido');
        return;
      }
      var payload = message.payload || {};
      if (message.type === 'session-joined') {
        setState('Esperando offer de Android');
        setSession('Sesion enlazada con Android');
        log('Sesion enlazada automaticamente');
        return;
      }
      if (message.type === 'offer') {
        handleSignalEvent('offer', message.sessionId || sessionId, payload);
        return;
      }
      if (message.type === 'ice-candidate') {
        handleSignalEvent('ice-candidate', message.sessionId || sessionId, payload);
        return;
      }
      if (message.type === 'disconnect') {
        setState('Sesion cerrada');
        setSession('Abre el emulador en Android y pulsa Streaming.');
        log(payload.reason || 'Sesion cerrada');
        closePeer();
        return;
      }
      if (message.type === 'error') {
        setState('Error de signaling');
        setSession(payload.message || 'No se pudo conectar con la emision activa');
        log(payload.message || 'Error de signaling');
      }
    });
    activeSocket.addEventListener('close', function () {
      if (socket !== activeSocket) return;
      setState('Signaling cerrado');
      log('Socket cerrado');
    });
    activeSocket.addEventListener('error', function () {
      if (socket !== activeSocket) return;
      setState('Error conectando signaling');
      log('No se pudo abrir el WebSocket');
    });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var sessionId = form.elements.sessionId.value.trim().toUpperCase();
      var pin = form.elements.pin.value.trim();
      connect(sessionId, pin);
    });
  }

  if (disconnectButton) {
    disconnectButton.addEventListener('click', disconnect);
  }

  function connectFromActiveSession(session) {
    var sessionId = String(session.sessionId || '').trim().toUpperCase();
    var pin = String(session.pin || '').trim();
    if (form) {
      form.elements.sessionId.value = sessionId;
      form.elements.pin.value = pin;
    }
    setHint((session.deviceName || 'Android') + ' esta transmitiendo ahora. Conectando...');
    var signalingUrl = String(session.signalingUrl || '').trim();
    if (signalingUrl.indexOf('http://') === 0 || signalingUrl.indexOf('https://') === 0) {
      connectHttp(sessionId, signalingUrl);
    } else {
      connect(sessionId, pin);
    }
  }

  async function findActiveSession() {
    if (!activeUrl) return;
    setState('Buscando emision activa');
    setSession('Comprobando tu cuenta Nelkano');
    try {
      var response = await fetch(activeUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      var data = await response.json();
      if (!response.ok || !data.ok) {
        setState('Inicia sesion');
        setSession(data.message || 'Inicia sesion para conectar con tu streaming.');
        setHint('Inicia sesion en la web con la misma cuenta Nelkano que usas en Android.');
        return;
      }
      if (!data.active || !data.session) {
        setState('Esperando Android');
        setSession(data.message || 'Abre el emulador en Android y pulsa Streaming.');
        setHint(data.message || 'Abre el emulador en Android y pulsa Streaming.');
        return;
      }
      connectFromActiveSession(data.session);
    } catch (error) {
      setState('No se pudo comprobar la sesion');
      setSession('Revisa la conexion e intenta recargar la pagina.');
      log('Error buscando sesion activa: ' + error.message);
    }
  }

  var params = new URLSearchParams(window.location.search);
  var sessionFromUrl = params.get('session');
  if (sessionFromUrl && form) {
    form.elements.sessionId.value = sessionFromUrl.toUpperCase();
    setSession('Sesion manual cargada desde la URL');
  } else {
    findActiveSession();
  }
}());
