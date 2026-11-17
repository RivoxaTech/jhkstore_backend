// A tiny mail server that accepts everything and keeps nothing: the "connect the mail account" video needs a server the test e-mail can really be
// sent to (a fake host would only show an error). It speaks just enough SMTP (EHLO, AUTH PLAIN / LOGIN, MAIL, RCPT, DATA, QUIT).
import net from 'node:net'

export function startSink(port = 2525) {
  const received = []
  const server = net.createServer((socket) => {
    let buffer = ''
    let data = false
    let auth = 0
    let mail = { from: '', to: [], body: '' }
    const say = (line) => socket.write(`${line}\r\n`)
    say('220 sink ESMTP ready')
    socket.on('data', (chunk) => {
      buffer += chunk.toString('utf8')
      let i
      while ((i = buffer.indexOf('\r\n')) >= 0) {
        const line = buffer.slice(0, i)
        buffer = buffer.slice(i + 2)
        if (data) {
          if (line === '.') { data = false; received.push(mail); mail = { from: '', to: [], body: '' }; say('250 2.0.0 queued') } else mail.body += `${line}\n`
          continue
        }
        if (auth === 1) { auth = 2; say('334 UGFzc3dvcmQ6'); continue }
        if (auth === 2) { auth = 0; say('235 2.7.0 accepted'); continue }
        const cmd = line.toUpperCase()
        if (cmd.startsWith('EHLO') || cmd.startsWith('HELO')) { socket.write('250-sink\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n') }
        else if (cmd.startsWith('AUTH PLAIN')) say('235 2.7.0 accepted')
        else if (cmd.startsWith('AUTH LOGIN')) { auth = 1; say('334 VXNlcm5hbWU6') }
        else if (cmd.startsWith('MAIL FROM')) { mail.from = line.slice(10); say('250 ok') }
        else if (cmd.startsWith('RCPT TO')) { mail.to.push(line.slice(8)); say('250 ok') }
        else if (cmd === 'DATA') { data = true; say('354 end with a dot on a line of its own') }
        else if (cmd === 'RSET' || cmd === 'NOOP') say('250 ok')
        else if (cmd === 'QUIT') { say('221 bye'); socket.end() }
        else say('250 ok')
      }
    })
    socket.on('error', () => {})
  })
  return new Promise((resolve, reject) => {
    server.once('error', reject)
    server.listen(port, '127.0.0.1', () => resolve({ port, received, close: () => new Promise((r) => server.close(() => r())) }))
  })
}
