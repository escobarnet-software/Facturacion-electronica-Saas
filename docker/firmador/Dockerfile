# Firmador oficial de Hacienda (svfe-api-firmador), compilado desde el código
# fuente publicado en factura.gob.sv. El Dockerfile original (Dockerfile.oficial)
# espera el .jar ya compilado; aquí se compila en una primera etapa.
FROM maven:3.9-eclipse-temurin-21 AS build
WORKDIR /src
COPY pom.xml .
RUN mvn -q -B dependency:go-offline
COPY src ./src
RUN mvn -q -B -DskipTests package

FROM amazoncorretto:21-alpine-jdk
ENV TZ=America/El_Salvador
# El application.yml oficial solo fija el puerto 8113 con el perfil "nonssl".
ENV SPRING_PROFILES_ACTIVE=nonssl
# Los certificados {NIT}.crt se montan en /uploads (solo lectura). Nunca se sirven por HTTP.
RUN mkdir -p /uploads
COPY --from=build /src/target/*-exec.jar /app.jar
EXPOSE 8113
ENTRYPOINT ["java", "--add-opens", "java.base/java.math=ALL-UNNAMED", "--add-opens", "java.base/java.time=ALL-UNNAMED", "-jar", "/app.jar"]
