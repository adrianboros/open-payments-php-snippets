FROM node:22-alpine
WORKDIR /srv
RUN npm init -y >/dev/null && npm install --omit=dev @interledger/http-signature-utils@2.0.3
COPY server.mjs .
EXPOSE 80 3006
CMD ["node", "server.mjs"]
